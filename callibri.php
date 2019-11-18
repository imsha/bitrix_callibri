<?php
/**
 * Скрипы выполняется по крону каждые 5 минут
 */

/**
 * Максимальное число обрабатываемых за раз звонков
 */
define('MAX_CALL_HANDLER', 30);

/**
 * Максимальное число дней для сбора истории, но не более 6
 */
define('MAX_HISTORY_DAYS', 30);

/**
 * Доступы к https://api.callibri.ru/
 * Документация https://callibri.ru/faq/KakvospolzovatsyaAPI
 */
define('CALLIBRI_USER_HOST', 'https://api.callibri.ru');
define('CALLIBRI_USER_EMAIL', 'blablalba@mail.ru');
define('CALLIBRI_USER_TOKEN', 'blablablabla');

/**
 * Путь к входящему вебхуку битрикс
 */
define('BITRIX_REST_API_URL', 'https://<your_host>/rest/<webhook_user_id>/<webhook_token>/');

/**
 * Конфиг пользовательских полей Bitrix
 */
define('UF_REL_UTM_SOURCE', 'UF_CRM_1574070654');
define('UF_REL_UTM_MEDIUM', 'UF_CRM_1574070697');
define('UF_REL_UTM_CAMPAIGN', 'UF_CRM_1574070732');
define('UF_REL_UTM_TERM', 'UF_CRM_1574070785');
define('UF_REL_UTM_CONTENT', 'UF_CRM_1574070817');
define('UF_REL_AUDIO_LINK', 'UF_CRM_1574086547741');
define('UF_REL_DATE', 'UF_CRM_1574087210725');


/**
 * Логика работы:
 *
 * 1. Выполняю файл по кронку раз в 5 минут.
 * 2. Получаю лиды за X последних дней. Если пару дней были сбои, одна из сторон была недоступна, то данные по лидам всеравно получатся.
 * 3. Исключаю лиды, которые уже загружены. Использую для этого поле XML_ID, в него пищу ID лида из callibri.ru
 * 4. Записываю лиды в базу
 */

/**
 * Метод для работы с Rest Api
 *
 * Использую решение из файла /webhook.php
 * @param string $method
 * @param array  $params
 *
 * @return array
 */
function requestToBitrix(string $method, array $params = []) : array
{
    $url = BITRIX_REST_API_URL . $method . '?' . http_build_query($params);
    $result = file_get_contents($url);
    return json_decode($result, true);
}

/**
 * Генерация URL запроса
 * @param       $method
 * @param array $params
 * @throws
 * @return array
 */
function requestToCallibri($method, array $params = [])
{
    $params = array_merge([
        'user_email' => CALLIBRI_USER_EMAIL,
        'user_token' => CALLIBRI_USER_TOKEN,
    ], $params);
    $url =  CALLIBRI_USER_HOST . '/' . $method . '?' . http_build_query($params);
    $result = file_get_contents($url);

    if(empty(trim($result))) {
        throw new Exception('Пустой ответ');
    }
    return json_decode($result, true);
}

/**
 * Интервал дат
 * @param DateTime $dateFrom
 * @param DateTime $dateTo
 * @param int      $chunkSize
 *
 * @return DatePeriod
 * @throws Exception
 */
function getDateIntervals(DateTime $dateFrom, DateTime $dateTo, int $chunkSize = 6) : DatePeriod
{
    return new DatePeriod(
        $dateFrom,
        new DateInterval('P' . $chunkSize . 'D'),
        $dateTo
    );
}

/**
 * Список доступных проектов
 *
 * @throws
 * @return array
 */
function getSitesFromCallibri()
{
    return requestToCallibri('/get_sites');
}

/**
 * Список обращений в проекте
 *
 * @param $siteId
 * @param DateTime $dateFrom
 * @param DateTime $dateTo
 *
 * @throws
 * @return array
 */
function getStatisticsFromCallibri(int $siteId, DateTime $dateFrom, DateTime $dateTo)
{
    return requestToCallibri('/site_get_statistics', [
        'site_id' => $siteId,
        'date1' => $dateFrom->format('d.m.Y'),
        'date2' => $dateTo->format('d.m.Y'),
    ]);
}

/**
 * Данные о контакте из CRM Callibri
 *
 * @param int $lidId
 *
 * @throws
 * @return array
 */
function getContactFromCallibri(int $lidId)
{
    return requestToCallibri('/crm/contact', [
        'lid_id' => $lidId,
    ]);
}

/**
 * Информация о посетителе в Яндекс.Метрике
 *
 * @param int $ymUid
 *
 * @throws
 * @return array
 */
function getMetrikaVisitorInfoFromCallibri(int $ymUid)
{
    return requestToCallibri('/metrika_visitor_info', [
        'ym_uid' => $ymUid,
    ]);
}

/**
 * Список оценок качества обслуживания (ОКО)
 *
 * @param int $siteId
 * @param DateTime $dateFrom
 * @param DateTime $dateTo
 *
 * @throws
 * @return array
 */
function getOkoStatisticsFromCallibri(int $siteId, DateTime $dateFrom, DateTime $dateTo)
{
    return requestToCallibri('/oko_statistics', [
        'site_id' => $siteId,
        'date1' => $dateFrom->format('d.m.Y'),
        'date2' => $dateTo->format('d.m.Y'),
    ]);
}

//Получаю массив сайтов в Callibri
$siteIds = array_column(getSitesFromCallibri()['sites'] ?? [], 'site_id');

//Получаю статистику по звонкам за 5 последних дней
$periods = getDateIntervals((new DateTime())->sub(new DateInterval('P' . MAX_HISTORY_DAYS . 'D')), new DateTime());


$callsCollection = [];
foreach ($siteIds as $siteId) {
    $result = [];
    foreach ($periods as $period) {
        $dateFrom = $period;
        $dateTo = $period->add(new DateInterval('P6D'));
        $result[] = getStatisticsFromCallibri($siteId, $dateFrom, $dateTo);
    }
    $calls = [];
    foreach ($result as $resultRow) {
        $calls = array_merge($calls, array_column($resultRow['channels_statistics'], 'calls'));
    }

    if(empty($calls)) {
        continue;
    }
    foreach ($calls as $row) {
        $callsCollection = array_merge($row, $callsCollection);
    }
}

if(!$callsCollection) {
    throw new Exception('Нет звонков за период');
}
//Получаю ID звонков, делаю выборку по существующим лидам
$callIds = array_column($callsCollection, 'id');

//Ищу лиды по ID источника
$leads = requestToBitrix('crm.lead.list', [
    'filter' => [
        '=ORIGIN_ID'     => $callIds,
        '=ORIGINATOR_ID' => 'callibri.ru',
    ],
    'select' => [
        'ORIGIN_ID',
    ]
]);

$alreadySavedCallIds = [];
if(!empty($leads['result'])) {
    $alreadySavedCallIds = array_filter(array_column($leads['result'], 'ORIGIN_ID'), function ($row) {
        return !empty(trim($row));
    });
}


foreach ($callsCollection as $index => $call) {

    if($index > MAX_CALL_HANDLER) {
        die(sprintf('За раз обрабатывается не более %s звонков, во избежание нагрузки на сервер', MAX_CALL_HANDLER));
    }

    //Если звонок не является лидом
    if(!$call['is_lid']) {
        continue;
    }

    //Пропускаю уже загруженные звонки
    if(in_array($call['id'], $alreadySavedCallIds)) {
        continue;
    }

    $contact = getContactFromCallibri($call['id']);

    $fields = [
        'ORIGIN_ID'     => $call['id'],
        'ORIGINATOR_ID' => 'callibri.ru',
    ];

    //Если есть данные по контакту, заполняю телефоны и email из него
    if($contact) {
        $fields['PHONE'] = array_map(function ($phone) {
            return [
                'VALUE'      => $phone,
                'VALUE_TYPE' => 'WORK',
            ];
        }, $contact['phones']);

        $fields['EMAIL'] = array_map(function ($email) {
            return [
                'VALUE'      => $email,
                'VALUE_TYPE' => 'WORK',
            ];
        }, $contact['emails']);

        if(!empty($contact['name'])) {
            $fields['NAME'] = $contact['name'];
        }

        if(!empty($contact['family'])) {
            $fields['LAST_NAME'] = $contact['family'];
        }

        if(!empty($contact['town'])) {
            $fields['ADDRESS_CITY'] = $contact['town'];
        }

        if(!empty($contact['position'])) {
            $fields['POST'] = $contact['position'];
        }

        if(!empty($contact['company'])) {
            $fields['COMPANY_TITLE'] = $contact['company'];
        }

    } else {
        if(!empty($call['phone'])) {
            $fields['PHONE'] = [
                [
                    'VALUE'      => $call['phone'],
                    'VALUE_TYPE' => 'WORK',
                ]
            ];
        }
        if(!empty($call['email'])) {
            $fields['EMAIL'] = [
                [
                    'VALUE'      => $call['email'],
                    'VALUE_TYPE' => 'WORK',
                ]
            ];
        }
    }

    //Заполняю остальные поля
    $fields['TITLE'] = sprintf('Входящий звонок %s', $call['phone']);
    $fields[UF_REL_UTM_SOURCE] = $call['utm_source'];
    $fields[UF_REL_UTM_MEDIUM] = $call['utm_medium'];
    $fields[UF_REL_UTM_CAMPAIGN] = $call['utm_campaign'];
    $fields[UF_REL_UTM_CONTENT] = $call['utm_content'];
    $fields[UF_REL_UTM_TERM] = $call['utm_term'];
    $fields[UF_REL_AUDIO_LINK] = $call['link_download'];
    $fields['SOURCE_ID'] = 'CALL';
    $fields['SOURCE_DESCRIPTION'] = $call['landing_page'];
    $fields[UF_REL_DATE] = (new DateTime($call['date']))->format('d.m.Y');

    //Создаю ЛИД
    $result = requestToBitrix('crm.lead.add', [
        'fields' => $fields,
        'params' => [
            'REGISTER_SONET_EVENT' => 'Y'
        ]
    ]);

    //Пауза пол секунды, чтобы не нагружать сервер
    usleep(500);

}


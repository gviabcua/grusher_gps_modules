
# Скрипт синхронізації даних трекерів GPS із UserSide до Grusher
###  Встановлення
Розпакувати в потрібну папку. Перейменувати **config-SAMPLE.php** на **config.php**

Вказати свої дані у *config.php*

    $TRACCAR_URL = 'http://localhost/api/';
    $TRACCAR_USERNAME = 'user';
    $TRACCAR_PASSWORD = 'pass';
    $GRUSHER_URL = 'http://localhost';
    $GRUSHER_API_KEY = ''; // by default no key
    $GRUSHER_TIMEOUT = 5;

Файл start.php запускати у планувальнику мінімум 1 раз на хвилину

###  Приклад CRON

    */1 * * * * /usr/bin/php /PATH/traccar_sync/start.php >> /dev/null 2>&1
де PATH - ваш шлях до папки

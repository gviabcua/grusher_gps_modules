
# Скрипт синхронізації даних трекерів GPS із MapOn до Grusher
###  Встановлення
Розпакувати в потрібну папку. Перейменувати **config-SAMPLE.php** на **config.php**

Вказати свої дані у *config.php*

     $MAPON_URL = 'https://mapon.com/api/v1/';
     $MAPON_API_KEY = 'XXXXXXXXXXXXXXXXXXX';
     $GRUSHER_URL = 'http://localhost';
     $GRUSHER_API_KEY = ''; // by default no key
     $GRUSHER_TIMEOUT = 5;

Файл start.php запускати у планувальнику мінімум 1 раз на хвилину

###  Приклад CRON

    */1 * * * * /usr/bin/php /PATH/mapon_sync/start.php >> /dev/null 2>&1
де PATH - ваш шлях до папки

Запуск кожні 30 секунд

    */1 * * * * /usr/bin/php /PATH/mapon_sync/start.php >> /dev/null 2>&1
    */1 * * * * sh -c 'sleep 30 && /usr/bin/php /PATH/mapon_sync/start.php >> /dev/null 2>&1

Запуск кожні 20 секунд

    */1 * * * * /usr/bin/php /PATH/mapon_sync/start.php >> /dev/null 2>&1
    */1 * * * * sh -c 'sleep 20 && /usr/bin/php /PATH/mapon_sync/start.php >> /dev/null 2>&1
    */1 * * * * sh -c 'sleep 40 && /usr/bin/php /PATH/mapon_sync/start.php >> /dev/null 2>&1
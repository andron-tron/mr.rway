# mr.rway
simle script for parsing email messages from poisk_vagon@mnsk.rw.by and put attached CSV to future processing

## Развертывание ##
**Linux**
```
dnf install php-imap
dnf install php-pecl-mongodb
```

**Windows**
Enable extension in php.ini
```
php-imap
php-fileinfo 
```
**any OS**
```
composer require mongodb/mongodb
composer require php-imap/php-imap
```
## Notes ##

Подключениея и Аккаунты настраиваются в `Config.php`

* подключаемся к почтовому ящику
* считываем все (новые) сообщения
* если сообщение от нужного нам абонента (см. `Config.php`) и оно содержит вложение 
    * сохраняем в файл во временной папке (`\tmp`)
    * копируем файл через SCP на целевой сервер
    * TODO: проверяем наличие файла на целевом сервере
    * если все норм - удаляем сообщение. Файл остается во временной папке на всякий случай.
    * ID сообщения логгируется в MongoDB

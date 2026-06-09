FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN printf "DirectoryIndex index.php index.html\n" > /etc/apache2/conf-enabled/directory-index.conf

COPY . /var/www/html/

EXPOSE 80

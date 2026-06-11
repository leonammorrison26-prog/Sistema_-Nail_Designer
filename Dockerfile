FROM php:8.2-apache

# Instala as extensoes do banco de dados.
RUN docker-php-ext-install pdo pdo_mysql

# Garante que apenas o MPM compativel com mod_php fique ativo no Apache.
RUN set -eux; \
    a2dismod -f mpm_event mpm_worker || true; \
    rm -f /etc/apache2/mods-enabled/mpm_event.load \
          /etc/apache2/mods-enabled/mpm_event.conf \
          /etc/apache2/mods-enabled/mpm_worker.load \
          /etc/apache2/mods-enabled/mpm_worker.conf; \
    a2enmod mpm_prefork; \
    apache2ctl -t

# Configura o arquivo padrao de inicializacao.
RUN printf "DirectoryIndex index.php index.html\n" > /etc/apache2/conf-enabled/directory-index.conf

# Copia os arquivos do projeto para a pasta do servidor.
COPY . /var/www/html/

EXPOSE 80

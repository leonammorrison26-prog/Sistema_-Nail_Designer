FROM php:8.2-apache

# Instala as extensoes do banco de dados.
RUN docker-php-ext-install pdo pdo_mysql

# Garante que apenas um MPM fique ativo no Apache.
RUN a2dismod -f mpm_event mpm_worker mpm_prefork || true \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork \
    && apache2ctl -M | grep mpm_ \
    && apache2ctl -t

# Configura o arquivo padrao de inicializacao.
RUN printf "DirectoryIndex index.php index.html\n" > /etc/apache2/conf-enabled/directory-index.conf

# Copia os arquivos do projeto para a pasta do servidor.
COPY . /var/www/html/

EXPOSE 80

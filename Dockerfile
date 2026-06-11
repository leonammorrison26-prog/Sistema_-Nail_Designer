FROM php:8.2-apache

# Instala as extensoes do banco de dados.
RUN docker-php-ext-install pdo pdo_mysql

# Garante que apenas o MPM compativel com mod_php fique ativo no Apache.
RUN set -eux; \
    find /etc/apache2/mods-enabled -maxdepth 1 -type l -name 'mpm_*' -delete; \
    ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load; \
    if [ -f /etc/apache2/mods-available/mpm_prefork.conf ]; then \
        ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf; \
    fi; \
    apache2ctl -M | grep 'mpm_'; \
    test "$(apache2ctl -M 2>/dev/null | grep -c 'mpm_')" = "1"; \
    apache2ctl -t

# Configura o arquivo padrao de inicializacao.
RUN printf "DirectoryIndex index.php index.html\n" > /etc/apache2/conf-enabled/directory-index.conf

# Copia os arquivos do projeto para a pasta do servidor.
COPY . /var/www/html/

# CRIA A PASTA DE UPLOADS E DA AS PERMISSÕES AO USUÁRIO DO APACHE (www-data)
RUN mkdir -p /var/www/html/assets/uploads && \
    chown -R www-data:www-data /var/www/html/assets && \
    chmod -R 775 /var/www/html/assets

EXPOSE 80

CMD ["bash", "-lc", "set -e; apache2fn() { apache2ctl -D FOREGROUND; }; apache2fn"]
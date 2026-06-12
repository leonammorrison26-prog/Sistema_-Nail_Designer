# Sistema Samara Eduarda Nail Designer

Sistema em PHP, SimpleXML, HTML, Tailwind CSS e MySQL para salao de manicure e pedicure.

## Recursos

- Catalogo publico com foto, valor e duracao dos servicos.
- Agendamento publico por servico, manicure, data e horario.
- Login de equipe com perfis `admin`, `manicure` e `manicure_admin`.
- Admin gerencia usuarios, servicos, horarios, redes sociais e a secao Sobre mim.
- Tabelas criadas/atualizadas automaticamente no MySQL.

## Login inicial

Ao abrir pela primeira vez, o sistema cria:

- Admin: `admin` / `123456`
- Manicure: `sammy@sammy.com` / `sammy123`

Troque essas senhas depois do primeiro acesso.

## Railway + Aiven

O projeto usa Dockerfile e esta pronto para Railway. Configure as variaveis no servico da aplicacao, em **Variables**.

Forma recomendada para Aiven:

```env
DATABASE_URL=mysql://USUARIO:SENHA@HOST_AIVEN:PORTA/NOME_BANCO
APP_TIMEZONE=America/Sao_Paulo
```

Tambem funciona com variaveis separadas:

```env
DB_HOST=HOST_AIVEN
DB_PORT=PORTA_AIVEN
DB_NAME=NOME_BANCO
DB_USER=USUARIO
DB_PASS=SENHA
APP_TIMEZONE=America/Sao_Paulo
```

Aliases aceitos para manter o Aiven como banco padrao:

```env
AIVEN_DATABASE_URL=mysql://USUARIO:SENHA@HOST_AIVEN:PORTA/NOME_BANCO
AIVEN_DB_HOST=HOST_AIVEN
AIVEN_DB_PORT=PORTA_AIVEN
AIVEN_DB_NAME=NOME_BANCO
AIVEN_DB_USER=USUARIO
AIVEN_DB_PASS=SENHA
```

Se a Aiven exigir certificado CA, copie o conteudo do CA certificate para uma variavel:

```env
AIVEN_CA_CERT="-----BEGIN CERTIFICATE-----
...
-----END CERTIFICATE-----"
```

Ou informe um caminho dentro do container:

```env
AIVEN_CA_CERT_PATH=/caminho/ca.pem
```

O app tambem aceita `MYSQL_SSL_CA` e `MYSQL_SSL_CA_PATH`.

## Persistencia no Railway

O banco fica na Aiven, mas arquivos enviados pelo painel e o `config.xml` precisam de persistencia se voce nao quiser perder mudancas em redeploy.

Opcao simples: crie um Railway Volume e monte em:

```txt
/var/www/html/assets/uploads
```

Para persistir tambem o `config.xml`, monte outro volume ou uma pasta compartilhada e defina:

```env
APP_CONFIG_PATH=/data/config.xml
UPLOAD_DIR=/var/www/html/assets/uploads
UPLOAD_URL_PREFIX=assets/uploads
```

Se voce nao configurar volume, o sistema ainda roda, mas uploads e alteracoes do `config.xml` podem sumir em um novo deploy.

## Notificacoes

E-mail usa `mail()` do PHP e WhatsApp usa a API Cloud da Meta:

```env
NOTIFY_FROM_EMAIL=agenda@seudominio.com
WHATSAPP_ACCESS_TOKEN=token_da_meta
WHATSAPP_PHONE_NUMBER_ID=id_do_numero_da_meta
```

Se essas variaveis nao existirem, o sistema continua funcionando; apenas nao envia a notificacao correspondente.

## Rodar localmente

Este projeto tem scripts locais para esta maquina:

```powershell
.\start-mariadb-local.ps1
.\start-php-local.ps1
```

Depois acesse `http://localhost:8000`.

Para parar:

```powershell
.\stop-local.ps1
```

Banco local:

- Host: `localhost`
- Porta: `3306`
- Banco: `salao_sammy`
- Usuario: `root`
- Senha: `root`

## Diagnostico rapido

Se aparecer `php_network_getaddresses` ou `getaddrinfo failed`, o problema e o host do banco. Copie novamente o Host e a Porta direto da tela **Overview > Connection information** da Aiven e atualize `DATABASE_URL` ou `DB_HOST` no Railway.

Depois de alterar variaveis no Railway, faca redeploy para aplicar.

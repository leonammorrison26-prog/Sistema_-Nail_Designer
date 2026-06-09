# Sistema Samara Eduarda Nail Designer

Sistema simples em PHP, SimpleXML, HTML, Tailwind CSS e MySQL para salão de manicure e pedicure.

## Recursos

- Catálogo público com foto, valor e duração dos serviços.
- Agendamento público por serviço, manicure, data e horário.
- Login de equipe com tipos `admin` e `manicure`.
- Admin cadastra/apaga usuários e gerencia serviços.
- Admin vê toda a agenda; manicure vê os próprios horários.
- Configurações do salão e horário de funcionamento em `config.xml` usando SimpleXML.

## Login inicial

Ao abrir pela primeira vez, o sistema cria:

- Login: `admin`
- Senha: `123456`
- Manicure inicial: `sammy@sammy.com` / `sammy123`

Troque essa senha criando outro admin e apagando o padrão, ou edite direto no banco.

## Banco de dados

Crie um banco MySQL e configure variáveis de ambiente:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=salao_sammy
DB_USER=root
DB_PASS=
APP_TIMEZONE=America/Sao_Paulo
```

Também funciona com `DATABASE_URL`, comum em hospedagens:

```env
DATABASE_URL=mysql://usuario:senha@host:3306/banco
```

As tabelas são criadas automaticamente pelo PHP. O arquivo `schema.sql` fica como referência se quiser importar manualmente.

## Notificações

Ao criar um agendamento, o sistema tenta avisar a manicure cadastrada no serviço:

- E-mail: usa o `mail()` do PHP e envia um convite de agenda `.ics` junto com a mensagem. Em hospedagem, configure o envio SMTP/servidor de e-mail. Opcionalmente defina `NOTIFY_FROM_EMAIL`.
- WhatsApp: usa a API oficial do WhatsApp Cloud. Defina `WHATSAPP_ACCESS_TOKEN` e `WHATSAPP_PHONE_NUMBER_ID`.

Exemplo:

```env
NOTIFY_FROM_EMAIL=agenda@samaraneil.com
WHATSAPP_ACCESS_TOKEN=token_da_meta
WHATSAPP_PHONE_NUMBER_ID=id_do_numero_da_meta
```

## Rodar localmente

Este projeto ja vem com scripts locais para esta maquina:

```powershell
.\start-mariadb-local.ps1
.\start-php-local.ps1
```

Depois acesse `http://localhost:8000`.

Para parar os servidores locais:

```powershell
.\stop-local.ps1
```

Banco local criado:

- Host: `localhost`
- Porta: `3306`
- Banco: `salao_sammy`
- Usuario: `root`
- Senha: `root`

O PHP portátil fica em `.tools/php`. Os scripts utilizam `$PSScriptRoot` para localizar as ferramentas automaticamente, independentemente da letra da unidade (C:, D:, etc).

## Render

No Render, use um serviço Web com PHP/Apache ou Docker PHP. Configure as variáveis do MySQL em Environment. O sistema usa Tailwind por CDN, então não precisa de build de frontend.

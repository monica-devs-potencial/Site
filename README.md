# site

PHP instant messaging site with real-time group chat.

## Features

- User registration & login (session auth, `password_hash`)
- Real-time chat via AJAX polling (2 s interval)
- **Emoji picker** — click 😊 to open the picker and insert emojis into messages
- **Image sending** — click 📎 to attach an image (JPEG, PNG, GIF, WebP; max 5 MB)
- **Admin delete messages** — admin users see a 🗑️ button on every message
- Online users sidebar
- Responsive layout (sidebar hidden on mobile)
- Full emoji support — stored and served in **utf8mb4** via MySQL

---

## ☁️ Deploy no Hostinger (hosting compartilhada)

### Pré-requisito: criar o banco de dados

1. Acesse **hPanel → Databases → MySQL Databases**.
2. Crie um banco de dados (ex: `u123456789_chat`).
3. Crie um usuário MySQL e anote a senha.
4. Vincule o usuário ao banco com **All Privileges**.

### Subir os arquivos

1. Faça upload de **todos os arquivos** do projeto para `public_html` (ou subdiretório) via File Manager ou FTP.
2. Certifique-se de que o PHP **8.2+** está selecionado e que a extensão **pdo_mysql** está habilitada (já ativa por padrão na Hostinger).

### Configurar via assistente (recomendado)

1. Acesse `https://seudominio.com/setup.php` no navegador.
2. Preencha os campos com os dados do banco criado acima.
3. Clique em **Testar conexão** para verificar.
4. Clique em **Salvar e instalar** — as tabelas são criadas automaticamente.
5. Clique em **Ir para o Chat** e crie sua conta.

> **Segurança:** após configurar, você pode apagar `setup.php` do servidor.

### Tornar o primeiro usuário admin

No **phpMyAdmin**, execute:

```sql
UPDATE users SET is_admin = 1 WHERE username = 'seu_usuario';
```

---

## 🐳 Quick start (Docker)

```bash
# 1. Copie o exemplo e ajuste os valores
cp .env.example .env

# 2. Suba os containers
docker-compose up --build -d

# 3. Acesse http://localhost:8080
```

As tabelas são criadas automaticamente na primeira requisição.

---

## ⚙️ Configuração manual (sem Docker, sem assistente)

Existem **três formas** de informar as credenciais (em ordem de prioridade):

### 1. `config.local.php` (mais simples no servidor)

Crie o arquivo `config.local.php` na raiz do projeto:

```php
<?php
define('MYSQL_HOST',     'localhost');
define('MYSQL_PORT',     '3306');
define('MYSQL_DATABASE', 'u123456789_chat');
define('MYSQL_USER',     'u123456789_user');
define('MYSQL_PASSWORD', 'sua_senha');
```

### 2. `.env`

Copie `.env.example` para `.env` e preencha:

```
MYSQL_HOST=localhost
MYSQL_PORT=3306
MYSQL_DATABASE=u123456789_chat
MYSQL_USER=u123456789_user
MYSQL_PASSWORD=sua_senha
```

### 3. Variáveis de ambiente (Docker / cPanel)

```bash
export MYSQL_HOST=localhost
export MYSQL_DATABASE=chat
export MYSQL_USER=chat
export MYSQL_PASSWORD=sua_senha
```

---

## 🔒 Notas de segurança

- `config.local.php` e `.env` são bloqueados pelo `.htaccess` (acesso direto negado).
- Imagens enviadas ficam em `data/uploads/` com nomes aleatórios.
- O tipo de arquivo é validado pelo magic-byte (`finfo`), não pelo MIME informado pelo cliente.
- Todo conteúdo de usuário é escapado com `htmlspecialchars` / `escapeHtml` antes de renderizar.

---

## 🧪 Teste de emoji (round-trip)

```bash
# Requer as variáveis de ambiente configuradas e MySQL rodando
php tests/emoji_roundtrip.php
```

---

## Variáveis de ambiente

| Variável          | Padrão      | Descrição                                      |
|-------------------|-------------|------------------------------------------------|
| `MYSQL_HOST`      | `localhost` | Host MySQL (`localhost` Hostinger, `db` Docker)|
| `MYSQL_PORT`      | `3306`      | Porta MySQL                                    |
| `MYSQL_DATABASE`  | *(vazio)*   | Nome do banco — **obrigatório**                |
| `MYSQL_USER`      | *(vazio)*   | Usuário do banco — **obrigatório**             |
| `MYSQL_PASSWORD`  | *(vazio)*   | Senha do banco                                 |

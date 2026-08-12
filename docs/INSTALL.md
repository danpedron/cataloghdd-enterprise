# Instalação do servidor

Este guia instala o CatalogHDD Enterprise em um servidor Linux com Nginx, PHP-FPM e MariaDB. Os comandos usam paths e domínios de exemplo; adapte-os ao seu ambiente.

## 1. Dependências

Instale Nginx, PHP-FPM com PDO MySQL, MariaDB e ferramentas de linha de comando adequadas à distribuição.

```bash
sudo apt update
sudo apt install nginx mariadb-server php-fpm php-mysql php-cli
```

Crie um banco e uma conta exclusiva. Substitua os valores de exemplo antes de executar.

```sql
CREATE DATABASE catalog_hdd CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'catalog_app'@'localhost' IDENTIFIED BY 'USE_UMA_SENHA_LONGA_E_UNICA';
GRANT ALL PRIVILEGES ON catalog_hdd.* TO 'catalog_app'@'localhost';
FLUSH PRIVILEGES;
```

## 2. Código e configuração

Instale o código fora da área pública. O único diretório exposto pelo Nginx deve ser `web/public`.

```bash
sudo install -d -o root -g www-data -m 0750 /opt/cataloghdd
sudo cp -a web /opt/cataloghdd/
sudo install -d -o www-data -g www-data -m 0750 /var/lib/cataloghdd/thumbnails
sudo install -d -o root -g www-data -m 0750 /etc/cataloghdd
sudo install -m 0640 -o root -g www-data config/catalog.env.example /etc/cataloghdd/catalog.env
sudo editor /etc/cataloghdd/catalog.env
```

Preencha `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_BASE_PATH` e `THUMBNAIL_DIR` no arquivo de ambiente. Restrinja o arquivo a root e ao grupo do processo PHP-FPM.

## 3. Banco de dados

Aplique as migrations em ordem, apenas uma vez por banco.

```bash
for migration in /opt/cataloghdd/web/migrations/*.sql; do
  mariadb -u root -p < "$migration"
done
```

## 4. Nginx e PHP-FPM

Copie [nginx.conf.example](../config/nginx.conf.example) para a configuração de sites do Nginx, substitua paths, domínio, certificado e socket PHP-FPM. Valide e recarregue somente após ajustar todos os caminhos.

```bash
sudo nginx -t
sudo systemctl reload nginx
```

> O parâmetro `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` é obrigatório para que o token Bearer do cliente alcance a API PHP.

## 5. Primeiro administrador

Use o utilitário de CLI uma única vez. Ele se recusa a criar outro administrador ativo.

```bash
cd /opt/cataloghdd
sudo -u www-data php web/bin/create_admin.php admin
```

Armazene a senha exibida em local seguro. A primeira sessão requer a troca de senha.

## 6. Cliente de indexação

Monte e instale o pacote Debian em um computador que terá acesso físico aos discos.

```bash
cd client
dpkg-deb --build --root-owner-group package cataloghdd-client_1.4.0_all.deb
sudo apt install ./cataloghdd-client_1.4.0_all.deb
```

No painel, crie um token para o cliente. Então configure e execute uma inspeção antes de indexar:

```bash
sudo cataloghdd configure --server 'https://catalog.example.com/catalog/' --token 'SEU_TOKEN'
cataloghdd inspect /dev/sdc
sudo cataloghdd index /dev/sdc
```

## Atualizações e backup

Faça backup consistente do banco e preserve uma cópia da versão em operação antes de atualizar. Aplique novas migrations em ordem e só depois substitua os arquivos da aplicação. O diretório de miniaturas também deve fazer parte do backup.

```bash
mariadb-dump --single-transaction --routines --events catalog_hdd | gzip -9 > catalog_hdd-$(date +%F).sql.gz
```

Não execute migrations de rollback sem uma estratégia validada de restauração; as migrations atuais são orientadas para avanço de schema.

# CatalogHDD Enterprise

**CatalogHDD Enterprise** é uma aplicação web auto-hospedada para catalogar HDDs, SSDs e mídias removíveis sem manter os discos conectados. O painel PHP/MariaDB armazena o inventário, permite pesquisar arquivos e apresenta os volumes catalogados. Um cliente Debian faz a leitura do dispositivo, monta sistemas de arquivos em modo somente leitura e envia o inventário por HTTPS.

> O objetivo é inventariar mídias offline. O cliente **não formata, repara, remove arquivos, altera permissões ou monta o dispositivo de origem em escrita**.

## Recursos

| Área | Recursos incluídos |
|---|---|
| Inventário | Modelo, serial, WWN, capacidade, transporte, partições e filesystems. |
| Pesquisa | Busca global por nome, extensão e caminho; resultados mostram o volume de origem. |
| Arquivos compactados | Enumeração pesquisável de ZIP, TAR, 7z, RAR e formatos suportados, sem extrair conteúdo. |
| Miniaturas | JPEGs leves de imagens e vídeos, com resolução e qualidade configuráveis. |
| Btrfs | Indexação de volumes Btrfs particionados ou diretamente no disco, com metadados de subvolumes. |
| Controle de acesso | Login, sessões seguras, papéis `admin`, `operator` e `viewer`, e permissões por volume. |
| Administração | Auditoria, tokens de indexação, preferências centralizadas e exclusão protegida de volume. |
| Interface | Página de volumes com alternância entre cards e tabela. |

## Arquitetura

```text
HDD/SSD ──> cliente Debian ──HTTPS + token──> API PHP ──> MariaDB
     │                                          │
     └─ montagem somente leitura                └─ painel web e miniaturas
```

A aplicação é organizada em três partes:

| Diretório | Conteúdo |
|---|---|
| `web/` | Aplicação PHP, migrations SQL, ativos estáticos e ferramentas administrativas. |
| `client/package/` | Estrutura do pacote Debian `cataloghdd-client`. |
| `config/` | Modelos seguros de configuração para ambiente e Nginx. |

## Requisitos

O servidor requer PHP 8.2 ou superior com `pdo_mysql`, MariaDB 10.5 ou superior, Nginx e um certificado TLS válido. O cliente é destinado a Debian ou derivados e requer Python 3.9 ou superior; o pacote instala as dependências essenciais para imagens, vídeo, Btrfs e formatos compactados.

## Instalação rápida do servidor

A implantação completa está em [docs/INSTALL.md](docs/INSTALL.md). Em resumo, use uma cópia privada do arquivo de ambiente, aplique as migrations em ordem e configure o web root para `web/public`.

```bash
sudo install -d -m 0750 /etc/cataloghdd /var/lib/cataloghdd/thumbnails
sudo install -m 0600 config/catalog.env.example /etc/cataloghdd/catalog.env
# Edite /etc/cataloghdd/catalog.env com credenciais exclusivas deste ambiente.

mariadb -u root -p < web/migrations/001_enterprise_schema.sql
mariadb -u root -p < web/migrations/002_ingestion_api.sql
mariadb -u root -p < web/migrations/003_disk_partitions.sql
mariadb -u root -p < web/migrations/004_virtual_archive_entries.sql
mariadb -u root -p < web/migrations/005_admin_preferences_and_runs.sql
```

Depois de configurar o Nginx com [config/nginx.conf.example](config/nginx.conf.example), crie o primeiro administrador:

```bash
sudo -u www-data php web/bin/create_admin.php admin
```

O comando mostra uma senha temporária uma única vez. Guarde-a em um cofre de senhas e altere-a no primeiro login.

## Cliente Debian

Construa o pacote no diretório `client/`:

```bash
dpkg-deb --build --root-owner-group client/package cataloghdd-client_1.3.0_all.deb
sudo apt install ./cataloghdd-client_1.3.0_all.deb
```

Gere um token de indexação no painel administrativo e configure o cliente com a URL da **sua** instalação.

```bash
sudo cataloghdd configure \
  --server 'https://catalog.example.com/catalog/' \
  --token 'SEU_TOKEN_DE_INDEXACAO'

cataloghdd inspect /dev/sdc
sudo cataloghdd index /dev/sdc
```

Antes de indexar, `inspect` apresenta o dispositivo detectado. A indexação monta partições elegíveis em `ro,nosuid,nodev,noexec`. Quando o filesystem está diretamente no disco, ele aparece como **Disco inteiro**. Em Btrfs, o cliente monta isoladamente o nível superior (`subvolid=5`) em modo somente leitura e preserva o ID/caminho de cada subvolume nos metadados de seus arquivos.

## Segurança operacional

Nunca versione o arquivo real de ambiente, tokens, senhas, bancos de dados, miniaturas ou arquivos `.deb` gerados. Use HTTPS e mantenha o cabeçalho `Authorization` encaminhado ao PHP-FPM, como demonstrado no exemplo Nginx. Os tokens são exibidos somente no momento da criação; trate-os como senhas e revogue-os pelo painel quando necessário.

Consulte [SECURITY.md](SECURITY.md) para relatar vulnerabilidades de maneira responsável.

## Contribuindo

Contribuições são bem-vindas. Leia [CONTRIBUTING.md](CONTRIBUTING.md), abra uma issue para discutir mudanças maiores e envie pull requests pequenos, testados e com descrição clara. O projeto usa um fluxo baseado em `main`; cada alteração deve ter um commit descritivo e ser enviada ao repositório remoto.

## Licença

Distribuído sob a licença [MIT](LICENSE).

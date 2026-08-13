# Changelog

Todas as alterações relevantes deste projeto são registradas neste arquivo.

## 1.4.2 — 2026-08-13

- Adicionado restaurador PHP completo do banco, com validação de todas as tabelas em InnoDB e `ROW_FORMAT=COMPRESSED`.

## 1.4.1 — 2026-08-12

- Corrigida a geração de miniaturas para PNG/GIF em paleta com transparência, eliminando avisos do Pillow sem descartar o canal alfa antes da composição em JPEG.

## 1.4.0 — 2026-08-12

- Redesenhada a consulta de conteúdo do volume como explorador hierárquico, com breadcrumbs, navegação para pasta-pai e pastas antes dos arquivos.
- Adicionado painel visual de volume com gráfico circular de espaço usado/livre, detalhes do dispositivo e indicadores operacionais.
- O cliente Debian passou a medir capacidade usada e livre do filesystem montado e enviar a telemetria ao servidor de forma autenticada.
- Adicionada migration para persistir as métricas de capacidade por partição.
- Removido o atributo de estilo inline do gráfico para cumprir CSP estrita sem `unsafe-inline` ou nonce adicional.
- Refinado o explorador para um workspace desktop com barra lateral contextual, ícones CSS, barra de localização e controles de navegação.
- Materializado o índice de diretórios do explorador para listar entradas diretas por chave indexada, evitando varredura de todos os descendentes em pastas grandes.
- Persistidos contadores de arquivos e tamanho por volume/partição para eliminar agregações completas durante a abertura de detalhes.

## 1.3.0 — 2026-08-11

- Adicionado suporte a filesystem diretamente no disco, sem tabela de partições, exibido como **Disco inteiro**.
- Adicionada indexação Btrfs segura no nível superior (`subvolid=5`) em modo somente leitura.
- Adicionados metadados de ID e caminho de subvolume Btrfs por arquivo.
- Adicionada alternância entre cards e tabela na página de volumes.
- Incluída dependência `btrfs-progs` no cliente Debian.

## 1.2.0 — 2026-08-11

- Adicionados tokens de indexação administráveis pelo painel.
- Adicionadas preferências centralizadas para compactados, miniaturas e limites operacionais.
- Adicionada exclusão administrativa protegida de volumes.
- Adicionados data, host e versão de cliente às informações de indexação por partição.

## 1.1.0 — 2026-08-11

- Cliente Debian com detecção de discos físicos, partições e montagem somente leitura.
- Miniaturas de imagens e vídeos, inventário de metadados e leitura de compactados.

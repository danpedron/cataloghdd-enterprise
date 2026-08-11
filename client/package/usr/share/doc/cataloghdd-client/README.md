# Cliente Debian — CatalogHDD Enterprise

## Instalação

Instale o pacote `.deb` com `apt`, para que as dependências, incluindo `btrfs-progs`, sejam resolvidas automaticamente.

```bash
sudo apt install ./cataloghdd-client_1.3.0_all.deb
```

O pacote instala o comando `cataloghdd`, o cliente Python em `/usr/lib/cataloghdd/` e uma configuração em `/etc/cataloghdd/client.conf`. Esse arquivo contém o token de indexação e deve permanecer acessível apenas ao root.

## Configurar o servidor

Crie um token no painel administrativo do CatalogHDD Enterprise. Em seguida, configure a URL HTTPS da sua própria instalação e o token.

```bash
sudo cataloghdd configure \
  --server 'https://catalog.example.com/catalog/' \
  --token 'SEU_TOKEN_DE_INDEXACAO'
```

> Trate o token como uma senha: não o inclua em repositórios, scripts compartilhados ou históricos de shell. Após a configuração, ele é salvo com permissão `600`.

## Inspecionar e indexar

Confira primeiro o dispositivo físico. A inspeção é somente leitura e não monta nem transmite dados.

```bash
lsblk -o NAME,PATH,TYPE,SIZE,MODEL,SERIAL,FSTYPE,MOUNTPOINTS
cataloghdd inspect /dev/sdc
sudo cataloghdd index /dev/sdc
```

O cliente detecta modelo, serial, WWN, capacidade e transporte. Em discos particionados, registra UUID, PARTUUID, rótulo, tamanho e filesystem de cada partição. Dispositivos sem tabela de partições também são suportados quando possuem um filesystem reconhecido diretamente no disco; eles aparecem no painel como **Disco inteiro**.

## Btrfs e subvolumes

Um volume Btrfs é montado temporariamente de forma isolada com `ro,nosuid,nodev,noexec,norecovery,subvolid=5`, permitindo enumerar a árvore do filesystem sem reutilizar uma montagem de escrita existente. O cliente identifica subvolumes Btrfs e armazena, nos metadados de cada arquivo, o ID e o caminho lógico do subvolume de origem.

## Compactados e miniaturas

Por padrão, o cliente gera miniaturas leves para imagens e vídeos e indexa a estrutura de arquivos compactados sem extrair ou executar seu conteúdo. O administrador pode definir a política centralizada no painel. Para uma execução específica, as opções locais ainda permitem desabilitar estes recursos:

```bash
sudo cataloghdd index /dev/sdc --no-archives --no-thumbnails
```

| Comando | Finalidade |
|---|---|
| `cataloghdd inspect /dev/sdc` | Exibe os dados do disco sem montar ou transmitir. |
| `sudo cataloghdd index /dev/sdc` | Indexa o disco conforme a política do servidor. |
| `sudo cataloghdd index /dev/sdc --dry-run` | Exibe o plano sem montar nem enviar dados. |
| `sudo cataloghdd index /dev/sdc --label BACKUP_2026` | Define um rótulo para o volume. |
| `sudo cataloghdd index /dev/sdc --no-archives` | Desabilita a enumeração interna de compactados. |
| `sudo cataloghdd index /dev/sdc --no-thumbnails` | Desabilita miniaturas nesta execução. |

O cliente não formata, repara, monta em escrita, altera permissões ou exclui dados do dispositivo de origem. Filesystems LUKS, LVM, RAID, swap ou ausentes são reportados como não indexáveis e não recebem tentativas de montagem automáticas.

## Filesystems adicionais

A instalação deve ter suporte ao filesystem que será montado. Em Debian, estes pacotes costumam ser necessários para NTFS e exFAT:

```bash
sudo apt install ntfs-3g exfatprogs
```

Ao final, abra o painel, consulte o volume pelo rótulo, serial ou modelo e pesquise seus arquivos mesmo com o disco desconectado.

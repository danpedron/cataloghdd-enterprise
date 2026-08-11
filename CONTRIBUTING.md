# Contribuindo com o CatalogHDD Enterprise

Obrigado pelo interesse em melhorar o projeto. Contribuições de código, documentação, testes, relatórios de falhas e sugestões de UX são bem-vindas.

## Antes de começar

Abra uma issue antes de iniciar mudanças amplas de arquitetura, banco de dados ou segurança. Isso evita trabalho duplicado e permite alinhar o objetivo da alteração. Não inclua tokens, URLs privadas, IPs, dumps de banco, caminhos pessoais, discos reais ou dados de usuários em issues, commits, pull requests ou screenshots.

## Fluxo de trabalho

1. Faça um fork e crie uma branch curta e específica, por exemplo `fix/btrfs-subvolume-scan`.
2. Mantenha a alteração focada e atualize a documentação quando houver mudança de comportamento.
3. Valide PHP, Python, migrations e o pacote Debian afetados.
4. Faça commits atômicos, com mensagens imperativas e descritivas.
5. Envie a branch ao GitHub e abra um pull request explicando problema, solução, testes e impacto de segurança.

Exemplos de mensagens adequadas:

```text
Fix Btrfs whole-disk detection
Add table view for cataloged volumes
Document API bearer forwarding in Nginx
```

Cada alteração aceita deve estar em um commit explícito e publicada no repositório remoto. Evite commits genéricos como `update`, `fix` ou `wip`.

## Padrões técnicos

O PHP deve continuar compatível com PHP 8.2+. O cliente deve continuar compatível com Python 3.9+. Evite dependências externas desnecessárias, preserve consultas parametrizadas, valide entradas na API e nunca relaxe as opções de montagem somente leitura. Mudanças em schema devem ser adicionadas como uma migration nova e idempotente quando possível; não reescreva migrations já publicadas.

## Testes mínimos

```bash
php -l web/public/index.php
php -l web/app/api.php
python3 -m py_compile client/package/usr/lib/cataloghdd/client.py
python3 -m py_compile client/package/usr/lib/cataloghdd/archives.py
dpkg-deb --build --root-owner-group client/package /tmp/cataloghdd-client_test_all.deb
```

Inclua também testes manuais relevantes no texto do pull request, especialmente para novos filesystems, APIs, permissões, importação de dados ou operações de exclusão.

## Código de conduta

Espera-se comunicação respeitosa, objetiva e inclusiva. Assédio, exposição de dados privados, comentários discriminatórios e comportamento hostil não serão tolerados.

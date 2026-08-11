# Política de segurança

## Relatar uma vulnerabilidade

Não abra issues públicas para vulnerabilidades que possam expor credenciais, sessões, volumes catalogados, conteúdo de arquivos, execução remota ou escalonamento de privilégio. Use o canal privado de contato configurado pelo mantenedor do repositório ou o recurso de **Security Advisories** do GitHub quando estiver disponível.

Inclua uma descrição objetiva, versões afetadas, passos de reprodução mínimos, impacto esperado e uma sugestão de mitigação quando possível. Remova tokens, senhas, URLs privadas, IPs, nomes de arquivos sensíveis e quaisquer dados pessoais antes de enviar o relato.

## Princípios de segurança do projeto

O CatalogHDD Enterprise busca manter a seguinte postura:

- o cliente monta apenas em modo somente leitura e não altera o disco de origem;
- tokens de indexação são armazenados apenas como hash no servidor;
- senhas usam hash resistente e a aplicação utiliza sessões com expiração e CSRF;
- acesso a volumes é controlado por papel e permissão delegada;
- miniaturas e configuração operacional ficam fora do web root;
- a API exige HTTPS e autenticação Bearer.

Administradores devem manter dependências e sistema operacional atualizados, usar senhas únicas, restringir permissões dos arquivos de configuração, revogar tokens inativos e revisar a trilha de auditoria periodicamente.

# P0 — interface Laravel de prévia demonstrativa: execução e gates

DRAFT / NO-MERGE / NO-CHARGE / NO-PUBLISH. Não implantado na Leaseweb.

## Versões e frentes preservadas

- Sites #484 `4b324c63b9450df780cc5be583a02113118aeb14`: base do workspace; #483 `814f0afd0741821696b42ff0c7fa180af6286ba1`: infraestrutura existente.
- Sites #485 `111af6ad3097099094a47efd1ee81d06fdc05881`: frente separada de evidências de aceite, inspecionada, não duplicada nem mesclada nesta candidata.
- Portal #916 `0e9b6f40361008fdacdbf7280dd1eb6d91bfdddb`, sobre #901 `5d88826d0f8a83455f11b44dbfd66d5b462ce267`: guard financeiro reaproveitado sem alterar sua origem de confiança.
- Sites #486: interface HTTP e plano de aplicação controlada. Código candidato e execução final descritos abaixo.

## O que esta candidata acrescenta

Interface Laravel/Blade e PHP-FPM reais, não um servidor de screenshots. Projetos demonstrativos Site Onepage ou Landing Page, briefing, renderer existente, HTML persistido, SHA-256 dos bytes, nova versão com controle de revisão esperada, versões anteriores e parecer editorial de outro principal SQL autorizado. Aviso demonstration_only preservado; nenhuma função converte a demonstração em contrato/pagamento/aceite do cliente.

Autenticação nominal no Nginx privado existente, com REMOTE_USER e marcador FastCGI exclusivamente do servidor; cabeçalhos HTTP homônimos não são autoridade. Map de credenciais próprio montado somente leitura, modo0400/0600, validade/revogação, limite de tamanho e proibição de dois nomes utilizarem o mesmo principal SQL. Cada requisição abre PDO próprio com TLS verify-full e verifica grant SQL por empresa; runtime privilegiado/owner/BYPASSRLS negado. Não compartilha credencial humana ou sessão do Portal.

CSRF Laravel nativo com cookie de sessão cifrado, Secure/HttpOnly/SameSite Strict. Sem senha/token em URL. HTML no-store e CSP sandbox sem scripts, formulários, rede ou framing. Briefing é texto escapado, sem uploads. Respostas de erro não expõem SQL/credenciais. Navegação e formulário adaptados para desktop/mobile, cores Imagine laranja/neutras.

Acesso de demonstração NÃO implementa a autoridade comercial Portal↔Sites. Não registrar identidades/certificados CI no staging. A aplicação é staging explícito, sem bypass local/testing para publicá-la.

## Testes executados

Código testado: `ed9bac73ba291fc509bf4b5365451ea1a31032b6`. [Run43963598002](https://github.com/rafhael100/imagine-sites/actions/runs/43963598002), job133637102063: aprovado. Vinte verificações de navegador/HTTP reais: anonimato401, cabeçalho de identidade forjado negado, aviso demo, criação, digest exato, CSP/no-store, CSRF ausente419, campo company_id rejeitado422, duas versões, bytes anteriores imutáveis, auto-revisão403, revisor nominal diferente, empresas cruzadas negadas, desktop1440/mobile390 sem overflow, Landing Page, renderer visível, persistência após restart do app e revogação SQL bloqueando nova leitura, auditoria persistente. Restart ocorreu exclusivamente no contêiner descartável CI, nunca na VPS.

Antes do schema: identidade real SQL/cluster conferida, cluster errado recusado com processo não zero e ausência do namespace confirmada antes da execução válida. PostgreSQL servido com TLS e PDO verify-full obrigatório. HTTPS do proxy verificado com curl+CA própria e CA incorreta recusada. O navegador deste CI aceita apenas o cenário descartável com ignoreHTTPSErrors para a CA temporária; esse ajuste não existe na aplicação, não comprova custódia operacional e jamais deve ser usado para acesso Leaseweb.

Dez execuções de workflow aprovadas na mesma HEAD, incluindo duplicadas push/PR; não são dez suítes independentes. Regressões F21, domínio, Laravel PHP8.3/8.4, staging Docker, staging privado e ingresso HTTP assinado passaram. PHP/Docker não estavam disponíveis no executor local; execução PHP/DB/browser comprovada no GitHub Actions.

Artifact `sites-private-laravel-demo-http`, id14274048931, digest `sha256:502ff1d63e8f4011909f58d73162bce412216c0ae21bfe683f94848c02f4f2e7`, quatro screenshots e resultado JSON sanitizado, expiração informada23/11/2026. Disponível no run acima. Não representa URL instalada, pagamento oficial ou autoridade comercial.

Inventário integral de arquivos da candidata relativo à #484: `SITES-P0-PREVIEW-HTTP-INVENTORY.json`. Relatório posterior altera somente documentação.

Falhas reais encontradas e corrigidas:

1. Primeiro CI: proxy conectado apenas à rede internal não disponibilizava o listener de loopback no host do runner. A fixture recebeu rede ingress própria exclusiva do proxy, mantendo app/PG privados. Nenhuma rede/porta da VPS mudou.
2. Jornada editorial: o teste deixou selecionado Solicitar ajustes e procurou aprovação. Corrigido para selecionar explicitamente approved_internal; a aplicação corretamente persistia o parecer escolhido. Não se alterou o controle de segregação para obter verde.
3. Autorrevisão adicional: map rejeita SQL login compartilhado entre nomes nominais; fixture usa senhas SQL distintas por principal. Não é parecer independente.

## VPS — somente leitura nesta rodada

Console autorizado acessado em16/11/2026 aproximadamente18:42 Brasília. `docker ps` confirmou app Sites healthy, PostgreSQL18 healthy, proxy running e bind127.0.0.1:18090. IDs observados Sites app fa789b1deae0, proxy c44505e9d3ce, DB3ffbdeeaa69b, iguais à observação anterior. CRM18081 e demais contêineres continuam em execução. Não foi executado deploy, build/pull, restart/reload, migration ou alteração de configuração na VPS.

Listagens nominais dos diretórios próprios confirmaram cópia local cifrada de35.888bytes e registros backup-integrity.json/backup-restore.json anteriores. Nenhum conteúdo de dump/chave foi aberto. Metadados não comprovam backup externo ou recuperação atual completa. O restore anterior da #482 é evidência histórica de oito tabelas core, não aprovação de recovery deste novo namespace.

No runtime de compra consultado, `/run/secrets` listou somente preview-runtime.json. O token esperado `/run/secrets/asaas_sandbox_api_token` não está montado ali. Isso não afirma ausência em todos os cofres; falta disponibilização pelo mecanismo seguro autorizado. Zero consulta autenticada Asaas, conciliação oficial ou pagamento nesta rodada.

## Portal → Sites e aceite

A inspeção da candidata do Portal confirma que PortalBrowserAuthentication e os resolvedores de sessão/membership/organização continuam negadores nesta frente. PreviewOrderListing lê purchase_rehearsal.orders; não é produtor oficial. SiteContractSnapshot é uma projeção, não prova de autenticação. Não foram substituídos por usuários demo ou flags positivas.

O factory Sandbox permanece bootstrap explícito, exige token montado e FullInstallationBindingSource antes do PSP. Preservados versão de oferta/contrato/política e guard contra considerar uma parcela como quitação integral. Não se escolheu política nova de parcelamento/liberação.

Resolvers e verificadores de aceite/revogação existentes permanecem reutilizáveis; revisão editorial local não é consentimento humano do cliente. #485 trata a ligação dos bytes persistidos com esses verificadores em laboratório. Falta autoridade nativa homologada de cliente/empresa/contrato, serviço mTLS próprio sob custódia, concessão vigente e fonte independente de revogação para completar a jornada comercial operacional.

## Revisão independente e rollback

Consulta de reviews #484 retornou vazia nesta rodada. Nenhum revisor independente identificado/parecer favorável foi fabricado. A revisão deste código pelo próprio implementador e os testes não atendem ao gate exigido pelo usuário. Não houve migration no staging.

Plano concreto em `ops/preview-candidate/README.md`: inventário privado antes da mudança, backup Sites completo atual cifrado/autenticado, restauração em destino isolado, revisão DBA de migration/grants/SECURITY DEFINER/RLS, custódia nominal, configuração de acesso privado e liberação de aplicação seletiva. A imagem candidata inclui resources e contratos necessários, mas não substituiu a imagem em execução.

Rollback proposto: desabilitar flag e retirar somente localização /_preview com validação e reload autorizados; voltar à imagem/config pinada anterior somente mediante plano seletivo aprovado; preservar porta/redes/banco/certificados/volumes e namespace com dados/histórico. Não executar down() populado, DROP CASCADE ou apagar volumes. Restore de dados, se indispensável, em novo destino isolado seguido de aprovação específica de cutover. É um plano para revisão, não rollback executado.

## Resultado e ações necessárias

| Item | Estado |
|---|---|
| Infraestrutura Sites existente | Preservada e observada em execução na VPS |
| Nova UI privada | Candidata testável em CI; não instalada no staging |
| Endereço novo Leaseweb | Inexistente nesta entrega |
| Como acessar após gates aprovados | VPN/túnel já autorizado até loopback18090, CA/hostname corretos e credencial nominal via custódia; então caminho /_preview. Não abrir localhost do computador sem túnel, nem ignorar TLS |
| Revisão independente segurança/DBA | Pendente de responsável distinto do implementador e parecer na SHA exata |
| Backup atual recuperável/externo | Pendente de destino autorizado, custódia e ensaio completo de recuperação atual |
| Aplicação controlada | Aprovação específica dos gates e do procedimento seletivo, sem recriar ambiente existente |
| Autoridade Portal e PKI | Cadastros, permissões Sites, sessões/MFA e serviço mTLS operacionais ainda não homologados |
| Asaas Sandbox | Token/conta/mapeamentos seguros indisponíveis no runtime consultado; sem chamada oficial |
| Aceite humano operacional | Pendente; não substituído por revisão SQL/fixture |

Próxima ação humana indispensável: indicar responsável independente de segurança/DBA para #484/#486 e o custodiante/destino autorizado de recuperação. Após evidências e aprovação dos gates, liberar especificamente o procedimento de atualização seletiva sem recriar a stack ou substituir banco/volume/certificados. Enquanto isso, código e CI avançaram; nenhuma aprovação genérica foi interpretada como cumprimento desses requisitos.

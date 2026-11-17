# Continuidade do workspace de prévias — 17/11/2026

## Problema e mudança

A prévia persistente de #486 já cria Onepage/Landing e registra versões, mas a tela não identificava o parecer da versão corrente por projeto. Todos os perfis recebiam formulários de edição/revisão. Erros de briefing viravam respostas genéricas e uma edição concorrente era indistinguível de falta de autorização. Além disso, o domínio contava bytes enquanto o formulário mostrava limites por caracteres, causando recusa de português acentuado dentro do limite.

Esta continuação sobre #488 (`ef1a8ba888c8862108b9329e0301764dbcbe8ddb`) implementa:

- Fila informativa por empresa: versão corrente, digest e parecer daquela versão exata. Uma nova versão volta a aguardar revisão; pedidos de ajustes prevalecem diante de pareceres divergentes. Uma consulta SQL usa o mesmo snapshot e confirma autorização/runtime antes e depois da leitura.
- Briefing validado por caracteres UTF-8, de um a oito benefícios de até180 caracteres, formato imutável e revisão esperada obrigatória na edição.
- Resposta422 com mensagens e campos preservados na própria resposta no-store, sem guardar conteúdo em cookie/sessão; conflito409 preserva os textos e mostra a revisão atual. Falhas de acesso continuam genéricas.
- Ações visíveis de acordo com capacidades; quem revisa consulta o briefing sem formulário de geração. Não se oferece auto-revisão nem repetição do próprio parecer imutável.
- Envelope HTTP limitado a32KiB, suficiente para os campos Unicode URL-encoded dentro dos limites, alinhado ao Nginx candidato. Não houve aplicação do proxy na VPS.
- Recusa de permissões extras efetivas em cada operação: schema/banco CREATE, grants de escrita/alteração/truncate/trigger, privilégios por coluna, PUBLIC/herança, owner membership, grant option, sequência/função de auditoria e perda de FORCE RLS.

## Verificação e revisão

PHP8.4.27/Composer isolados de fontes oficiais, dependências exatas do lock sem scripts/plugins. Nenhum manifesto de dependências alterado. Os testes locais novos de briefing passaram (7 testes/20 assertions), além de domínio (32), renderer (27), auditoria HTML (61) e structured copy (26). A suíte Laravel distingue os testes sem conexão dos testes SQL/opt-in pulados; estes skips não comprovam integração.

Revisor independente verificou controller, validação, principal e Blade e encontrou a diferença entre limite de caracteres e limite de transporte. O teto32KiB e um teste de pior caso Unicode corrigem esse achado. A revisão não constitui aprovação operacional de AppSec/DBA.

O laboratório SQL foi ampliado com63 alterações reais de privilégio temporárias. Cada tentativa deve falhar pela mensagem específica do guard e preservar dados; a retirada do privilégio deve recuperar leitura válida na mesma conexão. O laboratório também verifica a fila por tenant, revisão atual, revogação, expiração e ausência de escrita na consulta.

O navegador verifica Nginx/PHP-FPM/PostgreSQL18 reais descartáveis: criação dos dois produtos, erro422 com briefing preservado, conflito409 sem versão extra, formato imutável, perfil de revisor, fila antes/depois do parecer, Unicode longo, CSRF, empresa cruzada, versão anterior imutável, reinício e revogação. Screenshots e resultado JSON permanecem limitados a material sintético.

Apenas um workflow novo: `.github/workflows/sites-preview-validation.yml`. Restrito ao draft, branch/repositório conhecidos e SHA da base #488; permissões contents:read, sem segredos operacionais, checkout da HEAD do PR. Reutiliza os runners existentes. Nenhum workflow anterior foi alterado.

**Estado antes da primeira execução remota:** CI SQL/HTTP ainda pendente. A inicialização PostgreSQL local foi recusada pelo executor (socket Operation not permitted); não foi contornada. Não há alegação de teste SQL local.

## Resultado confirmado após o CI

[PR #489 — DRAFT](https://github.com/rafhael100/imagine-sites/pull/489). **SHA funcional verificada: `9ad188aa197e43135b5a992de5dfcf00df4cd8aa`.** A documentação posterior registra essa evidência sem alterar o código testado.

| Verificação | Resultado comprovado |
| --- | --- |
| PostgreSQL18, papéis, RLS, grants e fila | 250 assertions PASS; inclui63 configurações indevidas recusadas antes de escrever e oito checks de overview |
| Jornada HTTP/navegador | 33 checks PASS em Nginx/PHP-FPM/PG18 separados; desktop e390px sem overflow no workspace |
| Novos testes PHP de briefing e renderização | 10 testes,41 assertions PASS |
| Checks GitHub da HEAD funcional | 13/13 success; inclui matrizes8.3/8.4 e execuções duplicadas push/PR |
| Revisão visual | Workspace desktop/mobile inspecionados; campos, versões, parecer e ações legíveis |

- [Workflow funcional completo #44190839583](https://github.com/rafhael100/imagine-sites/actions/runs/44190839583).
- [Job SQL #134419484676](https://github.com/rafhael100/imagine-sites/actions/runs/44190839583/job/134419484676).
- [Job navegador #134419484163](https://github.com/rafhael100/imagine-sites/actions/runs/44190839583/job/134419484163).
- Artifact `sites-private-laravel-demo-http`, id14376686334, ZIP SHA256 `0446fd16afe09999c2af153b143bc50ebc426003a0bd9907034a8cd8684895d2`. `result.json` confirma33 checks, `operational=false`, `installed_on_vps=false`. Retenção do artifact até24/11/2026.

A primeira execução HTTP em a067c3d encontrou ParseError no fechamento adjacente de diretivas Blade. O erro foi reproduzido por render real, corrigido e coberto por três testes/21 assertions antes da execução verde. Nenhuma regra de autorização foi afrouxada para fazer o navegador passar.

O workflow novo filtra caminhos de código; registros documentais não repetem os laboratórios SQL/HTTP. A fonte dos resultados é sempre a SHA funcional acima, não uma suposição baseada no último commit documental.

## Limites para homologação

Os objetos da fixture HTTP ainda pertencem ao dono do laboratório; a fixture de papéis separa owner NOLOGIN/runtime. O guard recusa runtime associado aos owners, mas esta entrega não converte todos os proprietários nem aprova uma instalação operacional.

A fonte consultada no Portal #924 ainda descreve pedido pending_payment sem autorização de prévia. Não foi fabricada uma ponte que aceite flags do navegador como quitação/contrato. O vínculo autoritativo pedido→release e integração Asaas Sandbox/aceite continuam pendentes.

DRAFT; sem merge, deploy, execução na VPS, DNS, Cloudflare, cobrança, publicação de site ou dados de cliente. Preservados staging `imagine-sites-p0-staging`, porta127.0.0.1:18090 e demais produtos. Não há nova URL implantada. O próximo gate operacional continua separado e depende de backup/restauração externa, revisão/identidade e validação do fluxo completo.

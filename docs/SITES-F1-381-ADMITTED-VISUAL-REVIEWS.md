# F1-381 — Primeiras prévias navegáveis Onepage e Landing após pedido Portal→Sites

**Demonstração visual privada, gerada no GitHub CI a partir de duas admissões fictícias comprovadas no PostgreSQL. Sem dados de clientes ou autorização de publicação.**

## Problema resolvido

As F1-375–380 validaram a compra fictícia no PostgreSQL do Portal, assinatura da mensagem, entrega HTTP para o Laravel do Sites e a gravação de dois projetos e outbox. A F1-381 conecta a última etapa **a uma representação visual revisável**. A partir de um read model SQL limitado ao tenant do login autenticado, o Laravel executa `StructuredCopyCandidate::assemble` + `StaticSiteRenderer` para gerar duas prévias HTML: Onepage e Landing Page.

O conteúdo das páginas é **exclusivamente fictício e fixo**. Não existe chamada a provedor de IA, conteúdo do cliente, dados pessoais, formulário funcional, publicação, domínio, pagamento real ou aceite do Portal. O texto não é derivado do briefing comercial real — o objetivo aqui é validar que *um projeto do tipo Onepage ou Landing admitido* pode seguir para uma prévia visual segura.

## Controle por empresa

A função SQL `sites_f360.list_own_review_candidates()`, instalada **apenas no banco PostgreSQL descartável do CI**, usa `session_user` e `sites_f360.login_bindings` para listar somente a seleção de registros já admitidos da própria empresa. Não é dado SELECT direto na tabela de admissions. O teste exige dois projetos para a role `sites_f360_b` e zero para a role `sites_f360_a`.

O comando `php artisan sites:admitted-review-fixture` só funciona com CLI + `APP_ENV=testing` + `GITHUB_ACTIONS=true` + flag F1-381 e confirmação da identidade PostgreSQL. Não há rota HTTP de leitura, editor ou aprovação, e nenhum JSON de cliente é anexado ao artifact.

## Entregas visuais

O workflow cria um pacote descarregável do GitHub Actions com:

- `index.html` — painel de revisão responsivo com identidade Imagine (laranja #F9941D), cartões Onepage/Landing, prévias embutidas, ações para abrir cada página e avisos claros de NÃO PUBLICAR;
- `preview-onepage.html` — página institucional fictícia de rolagem única;
- `preview-landing-page.html` — página de campanha fictícia;
- `manifest.json` — apenas nome do arquivo, tipo do produto, bytes e SHA-256, sem referências de empresa/pedido/usuário e com `releaseGate=NO_GO`.

O conteúdo é validado contra limites de tamanho, hash de SHA-256, `noindex,nofollow`, ausência de formulários/scripts/URLs externos e integridade de bytes; falhas cancelam o workflow. O HTML e o índice têm CSS responsivo, navegação por teclado e não usam JavaScript. O pacote é retido **por sete dias** no GitHub e removido automaticamente pelo provedor após o prazo.

## Limites para homologação operacional

A prévia ainda não tem dados contratuais reais, edição AI ou manual autenticada, identidade de cliente, fluxo de comentários/versões persistido, aprovação do proprietário, geração de DNS, Cloudflare, captação de leads, publicação, monitoramento ou rollback. A identidade visual aqui é do **painel interno Imagine**; um site real deverá usar o branding aprovado de cada cliente.

A função SQL e os dados desta F1 são **fixtures descartáveis**, não migrações autorizadas de produção. Não liberar API pública nem converter um clique em prévia em aprovação de cliente. Faltam PHP/SQL versionados operacionais, revisão AppSec, IAM de operadores e Portal, plano de backup, autorização comercial/pagamento e entrega controlada.

**PR DRAFT/NO-GO, sem deploy e sem merge automático.**

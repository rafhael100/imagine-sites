# F1-264 — Módulo experimental Cloudflare Worker, publicação desativada

Foi criado um módulo JavaScript compatível com a interface de Cloudflare Workers, mas **sua rota exportada `fetch()` sempre devolve 503**. Variáveis de ambiente ou cabeçalhos de suposta autorização não ativam publicação. Não há manifesto de produção, acesso ao R2/KV, DNS ou credenciais.

Existe um helper separado `renderSyntheticPreview` destinado exclusivamente ao CI, que:

- Aceita somente domínio reservado `.invalid`, HTTPS, rota raiz, sem parâmetros e métodos GET/HEAD.
- Consome HTML fictício gerado pelo motor PHP real durante o job, com hash SHA-256 recalculado e estado de revisão interna pendente.
- Exige ausência de formulários, scripts e URLs remotas; aplica CSP restritiva, cache privado, `noindex`, `nosniff` e proibição de frame.
- Recusa hash alterado, HTML não canônico, estado publicável/aceito, métodos de escrita e domínios públicos.
- Nunca consulta a rede ou registra dados pessoais; o fixture local é apagado do runner depois da bateria de testes.

Este módulo ainda não implementa um publisher confiável. Próximas dependências reais: identidade S2S Portal, aceite expresso e imutável, release autenticada, armazenamento/backup, DNS/TLS, Cloudflare Workers e verificação independente da origem. Todos os dez gates de produção permanecem **BLOCKED**.

Para homologar futuramente não basta configurar uma env var; a implementação operacional deverá ser um módulo separado com autoridade verificada, observação externa e revisão AppSec.
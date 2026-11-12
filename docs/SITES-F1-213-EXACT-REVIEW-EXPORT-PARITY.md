# F1-213 — Identidade exata entre a prévia selecionada e os arquivos exportados

A F1-212 reuniu no revisor Laravel as escolhas de tema, cores, menu, ordem das seções e metadados sociais. A F1-209 já exportava essas escolhas em pacotes privados. Esta etapa exige que **os bytes do HTML exibido sejam os mesmos gravados em index.html** para a mesma combinação fixa.

A classe SyntheticVisualReviewExportParityV1 usa SyntheticPrivateDraftVerifier::verifyBytes e SyntheticPrivateSocialCardReadbackV1, compara SHA-256, releaseRef, HTML original completo, modo de metadados e estados de aprovação. Não aceita publishable=true, cliente aprovado, troca de HTML de outra paleta/ordem, metadata alterada ou reaproveitamento de artefato de outra versão. Todos os métodos de autorização de publicação, aceite do cliente, CRM e salto de revisão retornam false.

A rota de revisão F1-212 usa esse verificador para conferir o HTML em memória. Um teste Laravel mais abrangente monta a mesma seleção no revisor, gera o pacote físico via comando F1-205, lê o HTML persistido e verifica identidade byte a byte, integridade do manifesto, resposta do auditor F1-211, proibição de sobrescrita e rejeição de arquivo posteriormente adulterado.

Os testes de domínio abrangem 24 variantes e falsificações de conteúdo/manifesto. A matriz PHP 8.3/8.4 executa 12 pacotes de ponta a ponta, além de Onepage com menu e carta social ativados, validações adversariais e regressões do revisor F1-212 e auditor F1-211.

Tudo usa dados sintéticos sob GitHub Actions. Não é uma publicação, não autenticou cliente nem chamou Portal/CRM. Nenhum DNS, Cloudflare, VPS, fornecedor, credencial real, merge em main ou gasto novo. OP01/02/04 e os dez gates de produção permanecem bloqueados.

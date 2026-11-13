# F1-265 — Assinatura Ed25519 para prévia isolada do Worker

Foi implementado um verificador determinístico de assinatura Ed25519 para **prévia sintética** do Imagine Sites, integrado ao helper privado da F1-264. A chave privada é gerada apenas durante o teste e nunca acompanha o repositório ou um artefato.

- O envelope contém esquema, audiência fixa `preview.imagine.invalid`, três SHA-256 de correlação (escopo, revisão e HTML), relógios de emissão/expiração e nonce UUIDv4, sem PII.
- Serialização canônica com ordem de campos estável, tipos rigorosos, prazo máximo de cinco minutos e tolerância limitada de relógio.
- Assinaturas inválidas, chaves incorretas, alterações de digest, replay em outro site, URLs públicas, expirados e campos extras são recusados.
- O HTML da prévia continua sendo o HTML real do motor PHP, com QA, `noindex`, CSP e verificação SHA-256.
- A entrada exportada padrão do Worker **continua sempre devolvendo 503**, independentemente de env, cabeçalho, token ou suposta aprovação.

Assinatura válida significa apenas correspondência criptográfica com a chave fornecida: **não comprova que o assinante é autoridade Portal/Imagine**, não registra consentimento, não impede replay durável, não libera produção. A integração real exige chaves provisionadas, IAM S2S, revogação, nonce ledger, aprovação do cliente, observação independente e dez gates de go-live.
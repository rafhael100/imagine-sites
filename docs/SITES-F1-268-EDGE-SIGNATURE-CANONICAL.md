# F1-268 — Assinatura canônica Ed25519 e limite exato de expiração

A verificação das prévias assinadas F1-265 passa a rejeitar variantes textuais Base64URL que decodificam **a mesma assinatura**. Para 64 bytes Ed25519, os quatro bits finais de preenchimento não transportam informação; se o receptor aceitasse codificações alternativas, um mesmo identificador criptográfico poderia ser apresentado como textos diferentes e dificultar auditorias futuras.

- Depois de decodificar os 64 bytes, a entrada precisa ser exatamente igual à recodificação Base64URL canônica sem `=`.
- O instante `expiresAt` é agora **exclusivo**: no segundo exato da expiração a assinatura deixa de ser aceita.
- Novos testes demonstram que duas strings Base64URL diferentes representam bytes idênticos e exigem rejeição da variante; também cobrem um segundo antes, o segundo exato e depois do limite.
- A verificação ainda utiliza chave pública fornecida pelo teste, sem autenticar o dono, sem ledger durável de nonce, sem autorização Portal e sem publicar. A rota pública exportada pelo Worker continua respondendo HTTP 503.

O workflow F1-265 será executado novamente em Node 22. Dez gates operacionais continuam BLOCKED e não há Cloudflare, VPS, DNS, segredos ou dados de clientes.
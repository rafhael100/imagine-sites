# F1-107 — Leitura canônica do contrato candidato de autorização

## Problema

A F1-106 rejeita campos extras e tipos inválidos depois de receber um array PHP. Entretanto, ao converter JSON para array, bibliotecas podem descartar chaves duplicadas ou normalizar representações equivalentes. Um registro candidato de autorização precisa de uma forma não ambígua **antes** do parser de campos.

## Implementação

`CurrentPortalProjectAuthorizationSnapshot::fromCanonicalJson(string $raw)` recusa payload vazio ou maior que 4096 bytes, JSON inválido e objeto raiz que não seja um mapa. Decodifica com exceção em erro e exige igualdade exata entre a string recebida e a sua recodificação em JSON compacto, com `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. Só então chama o parser de estrutura F1-106.

Essa verificação recusa whitespace não canônico, chaves duplicadas (cujo descarte durante `json_decode` quebraria a igualdade de bytes), escapes Unicode alternativos, raiz em lista, conteúdo inválido, payload excessivo e tipos/estrutura rejeitados pelo F1-106.

`tests/f102-portal-project-snapshot.php` cobre payload canônico aceito e as negativas com PHP 8.3 e 8.4.

## Limites

O contrato exige JSON compacto na ordem transmitida; não define ordenação global de chaves para assinaturas digitais, logo não equivale a JSON Canonicalization Scheme (RFC 8785). **Não verifica assinatura, origem, identidade do usuário ou autorização do Portal.** A interpretação futura de um registro externo exige canal autenticado, verificação criptográfica, revisão/epoch corrente, autorização por recurso, auditoria e validação AppSec independente.

Laboratório somente. Não conecta serviços reais, não publica site, não fornece acesso a cliente, não altera VPS/Cloudflare nem cria custos. Os 10 gates operacionais seguem BLOCKED.

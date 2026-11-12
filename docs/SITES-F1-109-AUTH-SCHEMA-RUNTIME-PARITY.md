# F1-109 — Sentinela de divergência JSON Schema ↔ PHP para autorização Portal–Sites

## Motivação

As etapas F1-106/107 padronizaram a entrada do registro candidato de autorização do Portal por meio de contrato JSON Schema e decoder PHP estrito, mas os dois artefatos eram mantidos separadamente. Uma mudança inadvertida em apenas um deles poderia admitir formatos diferentes.

## Implementação

A F1-109 declara `WIRE_SCHEMA_VERSION` e `WIRE_FIELDS` em `CurrentPortalProjectAuthorizationSnapshot` e reutiliza essas constantes no decoder `fromArray`.

O teste independente `tests/f109-portal-auth-schema-parity.php` verifica diretamente os arquivos JSON Schema versionados do repositório, sem dependências de terceiros, comparando:
- lista de campos obrigatórios/propriedades da autorização com as constantes do decoder, e ausência de propriedades extras;
- versão do contrato e referência ao schema `create-project.schema.json`;
- campos/tipos da revisão, datas e estados `eligible`, `suspended`, `revoked`;
- campos do comando `SiteProjectCommand` com `create-project.schema.json`;
- parsing de objeto canônico válido, TTL de 30 segundos, expiração no limite, estados permitidos e rejeição de status/campo desconhecido.

O workflow `synthetic-php.yml` executa a nova sentinela em PHP 8.3 e 8.4, ao lado dos testes de contratos já existentes.

## Limitação

A sentinela **não implementa um validador JSON Schema universal**, nem valida automaticamente cada regra de expressão regular ou `$ref` de um documento arbitrário. Comprova invariantes críticos das duas estruturas **conhecidas**, complementando os testes comportamentais da F1-102/106/107 e o teste entre repositórios F1-108.

Ainda não existem origem confiável de snapshots, autenticação operacional do Portal, assinatura real, token de cliente, prova de aceite ou permissão de publicação. Apenas laboratório sintético; sem merge, deploy, VPS, DNS, dados pessoais ou custos. **10 gates operacionais BLOCKED.**

# F1-152 — Decoder canônico CreateProject v1 para o Sites

A F1-151 identificou uma lacuna: o validador do Portal F133 trabalha com **JSON canônico bruto**, enquanto `SiteProjectCommand::fromArray` aceitava um array já interpretado, mesmo quando o texto original continha chave repetida ou reordenação.

A F1-152 adiciona `SiteProjectCommand::fromCanonicalJson(string $raw)`, restrito a 4.096 bytes e à estrutura fechada de oito campos. A leitura exige objeto JSON, preservação da ordem oficial de chaves e igualdade exata entre o texto recebido e `json_encode` canônico da estrutura decodificada, antes de reaproveitar todas as validações de tipos e semântica já existentes em `fromArray`. Isso rejeita duplicatas, aliases, whitespace não-canônico e valores inesperados.

As regressões da F1-151 executam agora o novo método **contra o código real do Portal F133**, fixado no SHA `a997b7433e70259e05a3539a4e5301c8b5f79915`, em PHP 8.3/8.4. A compatibilidade é somente sintática e de serialização; não há assinatura, autenticação de origem, autorização de empresa/contrato, admissão real, HTTP ou publicação. Os métodos anteriores permanecem disponíveis para uso estritamente interno com arrays já validados. Um resultado estrutural correto **não concede autoridade operacional**.

PR draft sobre F1-151, 10/10 gates de produção BLOCKED e OP01/OP02/OP04 pendentes; sem merge dev/main, VPS, deploy ou custos.

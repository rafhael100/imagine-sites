# Imagine Sites F1-292 — contrato CreateProject v3 e proteção contra downgrade

**12/11/2026 | P0 OP01 (issue #33) | Candidato de documentação + provas executáveis | NÃO operacional**

## Divergência verificada

- O `openapi.candidate.json` anterior documentava a requisição `/v1/projects` usando `candidate-envelope.schema.json` **v1**, enquanto o fluxo de admissão de laboratório `LabSignedProjectIntake` já exige envelope **v3**, quando o modo comercial moderno é explicitamente ativado, incluindo épocas autenticadas de chave e autorização do pedido.
- Usar o contrato antigo como substituto silencioso representaria risco de regressão/downgrade ao conectar o Portal real. Um schema validado não comprova assinatura nem acesso autorizado.

## Correção proposta

- Novo arquivo `contracts/portal-sites/v1/commercial-epoch-envelope.v3.candidate.schema.json` com exatamente os dez campos da classe `SyntheticCommercialEpochSignedCommand` na ordem do emissor: `schemaVersion,issuer,audience,issuedAt,expiresAt,nonce,payload,keyEpoch,commercialEpoch,mac`.
- Novo arquivo `contracts/portal-sites/v1/openapi.v3.candidate.json`: apenas a operação CreateProject exige o envelope v3 explícito. O caminho lógico `/v1/projects` continua nomeando recurso CreateProject v1; o número v3 é **versão do envelope assinado**, não novo endpoint ativo. APIs adicionais no documento continuam propostas sem serviço.
- O OpenAPI legado v1 foi preservado inalterado e passa a ser **referência histórica**. Não existe fallback automático; assinatura v1/v2 deve ser recusada em um consumidor v3.
- Esquemas exigem campos fechados, issuer/audience fixos, épocas positivas, nonce e MAC hex. A ordem exata, TTL ≤120 s, autenticação mTLS real, assinatura, nonces duráveis e verificação de autoridade corrente exigem lógica operacional separada: o JSON Schema sozinho não consegue prová-los.

## Testes

1. `ci/f292_portal_v3_openapi_contract.py`: verifica JSON Schema/OpenAPI, campos e ordem contra o código PHP real, `MutualTLS` candidato, zero servidores, `202` sem concessão de autoridade, sem downgrade e preservação explícita do contrato histórico.
2. `tests/f292-v3-openapi-wire-policy.php`: usa a classe real de HMAC v3 do Sites para duas categorias de site; recusa assinatura falsa, épocas alteradas, troca de tenant, campo extra, versão v1/v2, TTL longo, timestamp vencido e replay em store efêmera. Não é prova de replay durável.
3. GitHub Actions PHP 8.3/8.4 e suíte PHP geral, sem dependências externas. Os testes não abrem sockets nem gravam dados de clientes.

## Para tornar OP01 operacional (ainda pendente)

- Fechar aprovação AppSec da identidade serviço-serviço (mTLS ou alternativa), emissor e audiência, cofre de chaves, revogação e rotação sem ABA.
- Confirmar no ambiente de homologação o contrato compatível com o código **atual** do Portal; snapshots comerciais e autorização por recurso devem vir de fonte confiável independente, não do envelope.
- Conectar replay nonce atômico, durável, não restaurável para trás; revalidar autoridade imediatamente antes do COMMIT e negar sob qualquer indisponibilidade.
- Definir sem ambiguidade resposta 202, erros 401/403/409/422/503 e comportamento idempotente; manter Portal responsável pelo aceite comercial/entrega e Sites pela publicação técnica.
- Provas independentes de cross-tenant, replay, concorrência, anti-revogação sob backup/restore, rotas HTTPS de teste e negação de privilégios; só depois revisar alteração de readiness.

**Gates:** dez flags `BLOCKED`, `dev/main` sem branch protection e revisão independente (#281). Sem merge, deploy, VPS, DNS, Cloudflare, segredo, tráfego ou cliente real.

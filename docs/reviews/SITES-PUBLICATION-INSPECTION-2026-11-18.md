# Imagine Sites — inspeção privada do diário técnico

## Uso concreto

O diário já conserva uma operação e sua última transição após perda de processo ou resposta. A API SQL/PHP de leitura existia, mas não havia um comando privado para consultar esse estado. Esta entrega torna executável a leitura de uma referência conhecida por um principal restrito já provisionado.

Branch `feature/sites-publication-inspection-2026-11-18`, sobre a PR #495 / `00597a48cd4513a32dff9bbc3f9c639e09e2a9e3`. A interface é `php ops/publication-candidate/inspect.php inspect`; `--help` funciona sem aplicação, banco ou arquivos privados. Não há listagem, filtro, caminho de saída ou opção mutável.

## Entrada e acesso

O manifesto `SITES_PUBLICATION_INSPECTION_V1` possui exatamente alvo SQL e UUID de operação. O alvo exige hostname, porta, banco, OID, login, endereço observado e SHA-256 da CA. A referência não concede autoridade e não informa a empresa: a função SQL existente deriva o escopo do principal.

Manifesto, senha e CA são montagens fixas sob `/run/secrets`, verificadas pelo leitor de arquivos privado já existente. O JSON é limitado a16KiB, com tipos estritos e rejeição de chaves adicionais/duplicadas, inclusive escapes equivalentes. O wrapper abre um PDO novo, não persistente, com TLS verify-full, timeouts e sessão somente leitura. Manifesto e CA são conferidos novamente antes da saída.

`PrivatePublicationInspection` exige `pg_catalog`, PostgreSQL18, identidade efetiva, isolamento read committed, RLS ligado, UTF-8, TLS e alvo observado iguais ao manifesto. Confere EXECUTE de `read_operation` sem grant option e ausência de EXECUTE das outras APIs de core/journal. Não consulta um rótulo privado de duty nem exige acesso adicional ao catálogo de principals. As condições são relidas depois da chamada.

O componente não faz SET ou escrita SQL. A CLI configura apenas a própria sessão. Não há migration, provisionamento, mudança de grants, binding, rota web, worker, transporte ou chamada a provider.

## Significado do resultado

A resposta contém14 campos explícitos: formato/status da consulta, estado técnico, revisões, número da tentativa, digests, datas e os dois indicadores de autoridade falsos. Nenhuma referência comercial, tenant, idempotência, operação, provider, token, senha ou conteúdo é exposta.

| Estado persistido | Interpretação |
| --- | --- |
| `requested` | Solicitação técnica registrada |
| `dispatched` | Tentativa registrada; não comprova envio |
| `acknowledged` | ACK relatado; não comprova publicação observada |
| `inconclusive` | Resultado inconclusivo registrado |

Um lease vencido não altera o resultado por conta própria. Se o banco ainda registra `dispatched`, a consulta devolve esse estado e o prazo, sem chamar reconciliação. A leitura histórica de uma release revogada continua possível para o leitor vigente, conforme o contrato já existente; isso não restaura nem valida o vínculo corrente.

Ausência, empresa alheia, leitor inválido e falha de infraestrutura retornam a mesma recusa limitada, com exit1, stdout vazio e `PUBLICATION_INSPECTION_DENIED` em stderr. Detalhes de PDO, parâmetros e cadeia de exceções são omitidos.

## Verificação

Executado localmente: **150 verificações offline do serviço**, **21 da CLI**, regressões existentes de diário99 e pacote50, lint PHP/Python/Bash, YAML e revisão independente. Os testes offline não simulam um sucesso de banco nem criam segredo ou principal.

A fixture real acrescenta **76 verificações esperadas**, incluindo a preparação de uma operação pelo helper existente. Usa dois leitores sintéticos restritos no laboratório PostgreSQL18/TLS já verificado e invoca a CLI efetiva. Compara linhas completas, catálogos, ACLs e arquivos privados antes/depois de leituras e recusas.

Os cenários incluem os quatro estados técnicos, binding histórico revogado, lease realmente vencido sem reconciliação, repetição, positivo autorizado da outra empresa, negativas cruzadas, alvo/CA/TLS, sessão e privilégios indevidos, custódia, credencial incorreta, expiração/revogação e ausência de valores privados na saída.

O helper de arquivos reserva somente seus três caminhos com criação exclusiva e recibo de identidade. A negativa de recibo deve preservar todos os arquivos; a limpeza verifica o recibo completo antes de remover qualquer caminho e não remove diretórios ou arquivos anteriores. O runner novo usa esse helper somente com a flag explícita de inspeção.

O workflow é restrito a repo, draft, branch e SHA exato da base e executa a matriz PHP8.3/8.4. A interface visual não mudou; seus175 checks e capturas já foram validados na PR #495. **CI PostgreSQL/CLI desta entrega pendente.** PostgreSQL local não foi iniciado.

## Continuidade e limites

O comando torna utilizável uma leitura existente. Não fecha a integração Portal → briefing → geração, cujo insumo oficial e primeiro consumidor permanecem indefinidos nas referências inspecionadas. A evidência e os próximos passos concretos estão em [Próxima integração central](SITES-NEXT-INTEGRATION-2026-11-18.md).

Os dez gates continuam BLOCKED, defaultDENY e AGI-11-RB01 desabilitado. Sem merge, deploy, VPS/18090, DNS, pagamento, aceite de cliente ou dados reais. Uso e custódia estão descritos no [manual da CLI](../../ops/publication-candidate/README.md).

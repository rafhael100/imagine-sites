# Briefing Onepage e consumo local — proposta v0.1

Estes arquivos são uma **proposta revisável**, sem endpoint, consumidor novo,
worker ou autoridade operacional. A especificação e as decisões ainda abertas
estão em [SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1](../../../../docs/SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md).

| Schema | O que descreve |
| --- | --- |
| `onepage-brief-payload.schema.json` | O shape privado de 11 textos e 3 booleanos derivado do inventário legado Onepage. |
| `brief-snapshot.schema.json` | Uma revisão imutável proposta, com referências de projeto, revisão agregada da origem, CAS próprio e descritores privados de bytes/materiais. |
| `brief-render-plan.schema.json` | A ligação proposta entre um snapshot exato, texto editorial selecionado separadamente e bytes esperados do renderer existente. |

Os schemas verificam estrutura e limites representáveis por JSON Schema
2020-12. Não verificam identidade, permissão, aprovação, hashes dos arquivos,
CAS, replay ou acesso a materiais. As anotações
`x-legacy-max-utf16-code-units-before-trim` **não são assertions de JSON Schema**:
um adaptador de compatibilidade precisa aplicar esses limites sem confundi-los
com `maxLength` em code points. Chaves duplicadas e formas numéricas canônicas
também exigem a validação adicional descrita na especificação.

O teto técnico proposto para o arquivo do briefing rico é **150000 bytes**, para
reutilizar o leitor privado existente. Ele é distinto dos 128000 bytes do body
HTTP legado e dos limites UTF-16 de cada campo; serializações acima do teto
precisam ser recusadas, sem truncar ou reescrever o payload. O input editorial
mantém os **32768 bytes** já admitidos pelo renderer. Um futuro consumo deste
plano precisa de quatro arquivos distintos: plano, manifesto do snapshot,
briefing rico e input editorial.

## Exemplos inteiramente sintéticos

| Arquivo | Função no exemplo |
| --- | --- |
| `examples/onepage-brief.synthetic.json` | Corpo rico privado, referenciado pelo manifesto. Contatos e domínio usam `.invalid`; nenhum dado é de cliente. |
| `examples/brief-snapshot.synthetic.json` | Exemplo de `sourceVersion=12` agregado e `briefRevision=1` novo, com `expectedBriefRevision=0`. A declaração `sourceState=approved` não é prova de aprovação. |
| `examples/render-input.synthetic.json` | Os seis campos já aceitos por `LocalSiteRendering`, escolhidos manualmente. Não é o corpo rico renomeado nem uma transformação automática dele. |
| `examples/rendered.synthetic.html` | Bytes reais do renderer determinístico existente aplicados somente ao input fictício acima. Continua uma demonstração inerte. |
| `examples/brief-render-plan.synthetic.json` | Liga o SHA do arquivo de snapshot, o SHA/tamanho do input editorial e o SHA/tamanho do HTML esperado. Não é recibo de execução ou registro. |

O exemplo não contém materiais: declara logo e manual indisponíveis. Não prova
upload, validação de conteúdo, autorização de download ou conferência humana.
O perfil de origem é somente Onepage; o suporte local do renderer a Landing
Page não define um briefing oficial desse produto.

Cada SHA-256 identifica os **bytes completos do respectivo arquivo**, sem
re-encode de JSON, ordenação de chaves, trim ou normalização Unicode. Os JSONs
do exemplo terminam em LF; o HTML mantém a ausência de LF final produzida pelo
renderer. Alterar espaçamento ou LF do snapshot exige atualizar
`snapshot.manifestSha256` do plano. As referências opacas de payload não são
caminhos nem URLs e não autorizam leitura.

O exemplo foi materializado com
[`LocalSiteRendering::render`](../../../../src/Production/LocalSiteRendering.php),
sem alterar seu parser, template, avisos ou digest. Isso demonstra uma ligação
técnica entre arquivos fictícios; não demonstra consumo de briefing oficial,
persistência de proveniência, idempotência durável, aprovação editorial ou
publicação. A proposta reutiliza as entradas existentes quando houver um
consumidor implementado; não instala ou ativa componentes.

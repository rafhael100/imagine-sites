# F1-204 — Catálogo único das escolhas visuais privadas

A revisão F1-203 permite escolher tema, cor, ordem das seções e navegação na mesma tela, mas mantinha mapas de cores e ordens embutidos na rota Laravel. A F1-204 cria a classe `SyntheticVisualPresetCatalog` como fonte única e fechada para essas escolhas.

O catálogo fornece seis permutações das seções Soluções/Como funciona/FAQ, quatro paletas fictícias predefinidas (default, ocean, plum e forest) e a seleção tipada do produto, tema, ordem e menu. A Landing Page nunca recebe menu adicional; não existe entrada de CSS livre, URL, script ou permissão de publicar. As três paletas não padrão usam `SyntheticBrandPaletteV1` e continuam validando contraste >=4,5:1. A seleção original é estritamente classic/ordem padrão/menu desligado/paleta default.

As rotas privadas de laboratório F1-191 e F1-203 reutilizam a mesma paleta e F1-203 reutiliza o mesmo mapa de ordem e critério de baseline. A saída de HTML, os hashes SHA-256, o comportamento de aprovação pendente e os demais caminhos permanecem iguais. O próximo exportador privado de revisões deverá usar este catálogo, evitando que UI e CLI aceitem opções diferentes.

A suíte `tests/f204-visual-preset-catalog.php` valida todas as 216 combinações suportadas, contraste e gramática restrita de CSS, unicidade das seis ordens, invariantes de baseline e nove seleções inválidas. O teste roda no Sites Engine PHP 8.3/8.4; as regressões do revisor F1-191/203 rodam na matriz Laravel e Docker já existente.

Este catálogo é *somente um contrato de laboratório*: não autentica o Portal, aprova o cliente ou libera envio de leads, publicação, faturamento ou deploy. OP01/OP02/OP04 e dez gates operacionais continuam bloqueados, com PR draft, sem merge em main, VPS ou novos custos.

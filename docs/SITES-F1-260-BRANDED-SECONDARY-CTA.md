# F1-260 — CTA secundário com identidade visual da marca

O motor de Onepage e Landing Page agora aplica ao CTA secundário as cores que já foram validadas na paleta de marca da própria página. O resultado é um botão coerente com o CTA principal, com contraste mínimo de 4,5:1 e borda explícita para manter a delimitação sobre fundos claros.

- Sem paleta personalizada, o CSS e os hashes históricos da F1-253 continuam idênticos.
- Com paleta personalizada, o texto e o fundo usam apenas os quatro tokens tipados do `SyntheticBrandPaletteV1`; a IA e o briefing textual não fornecem CSS livre.
- O novo CSS é verificado pelo comparador byte-a-byte dos quatro arquivos privados, permitindo **somente** a diferença esperada do CTA.
- A matriz cobre 2 produtos × 3 temas × 4 paletas, com 144 asserções e negativos de adulteração.

Somente rascunhos sintéticos, `noindex`, sem formulários, dados de clientes, rede, publicação, CRM ou autorização operacional. Os dez gates de produção permanecem bloqueados.
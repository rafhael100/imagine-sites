# F1-273 — Validação da FAQ expansível com teclado no Chromium real

Testes de semântica DOM não bastam para garantir que `<details><summary>` funcione no navegador. Esta entrega cria HTML sintético com o motor PHP real para Onepage e Landing Page, confere o pacote privado e exercita a interface no Chrome headless instalado no runner.

- São seis execuções: dois produtos, desktop 1365 px, mobile 390 px e tela estreita 320 px.
- Verifica que as duas perguntas aparecem fechadas inicialmente, com título acessível e área clicável de pelo menos 44 px.
- Foca a primeira pergunta e envia eventos reais de tecla **Enter** (abre) e **Espaço** (fecha). **Tab** precisa alcançar a segunda pergunta e Enter deve abri-la.
- Confere ausência de rolagem horizontal, script, formulário ou iframe e presença de `noindex, nofollow` no HTML.
- As páginas provêm do pipeline PHP de revisão e são verificadas contra os hashes e os quatro arquivos privados; não existe servidor web externo, leitura de clientes nem screenshot persistido.

O Chrome usa apenas CDP no loopback sobre `about:blank`. A documentação não afirma conformidade WCAG completa. O recurso não permite publicação, aceitação, CRM nem mudança dos dez gates `BLOCKED`.
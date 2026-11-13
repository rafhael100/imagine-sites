# F1-231 — proveniência separada entre prévia e exportação SEO

O laboratório tem duas origens deliberadamente independentes: a prévia Laravel interna F1-222 (`f222`) e a exportação privada CLI F1-223 (`f223`). Ambas usam o mesmo catálogo fictício e podem renderizar **HTML idêntico** para o mesmo produto, tema, modo SEO e metadados sociais; porém pertencem a escopos e referências de release diferentes.

A nova suíte `tests/f231-seo-cross-channel-provenance.php` comprova, nas 12 combinações de produto × tema × social, que:

1. HTML, título e descrição podem ser byte-idênticos em ambos os canais sem que identidade seja compartilhada.
2. O hash de correlação de escopo, as referências de release e os registros JSON de evidência permanecem distintos e determinísticos.
3. Nenhuma comparação pode usar pacote `default` de uma origem e `custom` da outra, mesmo com HTML válido.
4. Nenhum registro de evidência de um canal pode ser reaproveitado como evidência do outro.
5. Aceite, publicação, indexação e escrita no SEO Engine continuam falsos.

A suíte roda em PHP 8.3/8.4 na pipeline principal de testes sintéticos, **sem rede, dados reais, serviços pagos ou escrita em disco**. Não prova identidade operacional nem cria um vínculo oficial Portal–Sites; uma verificação de hash não é autenticação, e os gates da produção continuam `BLOCKED`.

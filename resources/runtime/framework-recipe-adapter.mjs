import { pathToFileURL } from 'node:url';

const [frameworkEntry] = process.argv.slice(2);
const framework = await import(pathToFileURL(frameworkEntry));
const chunks = [];
for await (const chunk of process.stdin) chunks.push(chunk);
const request = framework.parseRecipeJson(Buffer.concat(chunks).toString('utf8'));
const sources = new Map();
for (const source of request.sources || []) {
  const key = JSON.stringify([source.kind, source.owner, source.ref]);
  if (sources.has(key)) throw new Error(`duplicate source ${source.ref}`);
  sources.set(key, source);
}
const result = await framework.resolveRecipe(request.recipe, {
  trustedContext: request.trustedContext,
  inputs: request.inputs,
  executionContract: request.executionContract,
  ports: {
    readReference: async (reference) => {
      const source = sources.get(JSON.stringify([reference.kind, reference.owner, reference.ref]));
      return source ? { source: source.source, revision: source.revision, generation: source.generation } : { status: 'missing' };
    },
  },
});
if (!result.document) {
  process.stdout.write(JSON.stringify(result));
  process.exit(1);
}
const rendered = await framework.render(result.document);
if (rendered.diagnostics.length) throw new Error(rendered.diagnostics[0].message);
process.stdout.write(JSON.stringify({ ...result, html: rendered.html, assets: rendered.assets, documentDigest: rendered.digest }));

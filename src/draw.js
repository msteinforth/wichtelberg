import crypto from 'node:crypto';

/**
 * Auslosung als ein einziger Kreis: zufällige Permutation (Fisher-Yates mit
 * kryptografisch sicherem Zufall), jeder beschenkt den Nächsten, der Letzte den Ersten.
 * Garantiert: niemand beschenkt sich selbst; ab 3 Personen keine gegenseitigen Paare.
 *
 * @param {string[]} ids
 * @returns {Map<string, string>} Schenkender -> Beschenkter
 */
export function drawCycle(ids, randomInt = crypto.randomInt) {
  if (ids.length < 2) throw new Error('Für eine Auslosung sind mindestens 2 Teilnehmer nötig.');
  const order = [...ids];
  for (let i = order.length - 1; i > 0; i--) {
    const j = randomInt(i + 1);
    [order[i], order[j]] = [order[j], order[i]];
  }
  const result = new Map();
  order.forEach((id, i) => result.set(id, order[(i + 1) % order.length]));
  return result;
}

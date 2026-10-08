// Renders index.html to frames/ at 30fps, then muxes with a synth track into ollama-reel.mp4.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'), path = require('path');
(async () => {
  const dir = path.join(__dirname, 'frames'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await b.newPage({ viewport: { width: 1080, height: 1920 } });
  await pg.goto('file://' + path.join(__dirname, 'index.html'));
  await pg.waitForFunction('window.ready');
  const c = await pg.$('canvas');
  for (let i = 0; i < 450; i++) {
    await pg.evaluate(t => seek(t), i / 30);
    await c.screenshot({ path: path.join(dir, String(i).padStart(4, '0') + '.png') });
  }
  await b.close();
  // mix.wav = music.wav + ElevenLabs voiceover, mixed to -14 LUFS
  execSync(`ffmpeg -y -loglevel error -framerate 30 -i ${dir}/%04d.png -i ${__dirname}/mix.wav -c:v libx264 -pix_fmt yuv420p -crf 20 -c:a aac -b:a 160k -shortest -movflags +faststart ${__dirname}/ollama-reel.mp4`);
  execSync(`ffmpeg -y -loglevel error -i ${dir}/0060.png -i ${dir}/0130.png -i ${dir}/0290.png -i ${dir}/0380.png -filter_complex "[0][1][2][3]hstack=4,scale=1600:-1" -frames:v 1 ${__dirname}/contact.png`);
})();

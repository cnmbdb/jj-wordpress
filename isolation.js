const { Cam, fit, proj, facing, rings, prism, solid, put, mk, poly, open,
  ringAt, run, seg, clamp, spring, stepS, pointer, register, disposer } = HL;

function mount({ stage, svg, read }, value) {
  const bag = disposer();
  let reach = value, last = null;
  const C = Cam(45, 0.5, 1.72);
  fit(C, [[-58,-48,0],[58,-48,0],[-58,48,0],[58,48,0],[-58,-48,99],[58,48,99]], 200, 166);
  const P = proj(C), front = facing(C);
  const baseRing = rings(-58,-48,58,48,8,2);
  const coverRing = rings(-52,-42,52,42,7,1.8);
  const windowRing = rings(-36,-28,36,28,6,1.2)[0];
  const chipRing = rings(-23,-23,23,23,5,1.5);
  const dieRing = rings(-12,-12,12,12,3,1);
  const g = mk('g', {}, svg);
  const base = solid(g);
  put(base, prism(P, front, ...baseRing, 0, 8));
  // The rear rails belong behind the chip, the front rails in front of it.
  const rear = mk('path', { class: 'nf lo' }, g);
  const pins = mk('path', { class: 'nf' }, g);
  const chip = solid(g), die = solid(g);
  const near = mk('path', { class: 'nf lo' }, g);
  const cover = solid(g);
  cover.sil.setAttribute('fill-rule', 'evenodd');
  const windowEdge = mk('path', { class: 'nf lo' }, cover.g);
  const lid = spring(0), lift = spring(0);
  let active = false;
  function draw() {
    const z = 45 + lid.x, cz = 14 + lift.x;
    const rails = (keep) => open(ringAt(P, run(coverRing[0], keep), 9)) +
      open(ringAt(P, run(coverRing[0], keep), z));
    rear.setAttribute('d', rails(q => !front(q)) +
      seg(P(-45,-35,9),P(-45,-35,z)) + seg(P(45,-35,9),P(45,-35,z)));
    const leads = [];
    for (let i = -2; i <= 2; i++) {
      const a = i * 7;
      leads.push(seg(P(a,-30,cz+2),P(a,-22,cz+2)),seg(P(-30,a,cz+2),P(-22,a,cz+2)),
        seg(P(a,22,cz+2),P(a,30,cz+2)),seg(P(22,a,cz+2),P(30,a,cz+2)));
    }
    pins.setAttribute('d', leads.join(''));
    put(chip, prism(P, front, ...chipRing, cz, cz+6));
    put(die, prism(P, front, ...dieRing, cz+6, cz+8));
    near.setAttribute('d', rails(front) + seg(P(-45,35,9),P(-45,35,z)) + seg(P(45,35,9),P(45,35,z)));
    const cap = prism(P, front, ...coverRing, z, z+3);
    cap.sil += poly(ringAt(P, windowRing, z+3));
    put(cover, cap);
    windowEdge.setAttribute('d', poly(ringAt(P, windowRing, z+3)));
    cover.sil.classList.toggle('hi', active);
    die.sil.classList.toggle('hi', !active);
  }
  const B = register(stage, dt => {
    const a = stepS(lid, dt), b = stepS(lift, dt);
    const key = [lid.x, lift.x, active].join(',');
    if (key !== last) { draw(); last = key; }
    return a || b;
  });
  bag.add(B.unregister);
  function respond(point) {
    active = !!point;
    const t = point ? clamp((point[0] - 65) / 270, 0, 1) : 0;
    lid.t = active ? reach * (0.4 + 0.6*t) : 0;
    lift.t = active ? lid.t * 0.38 : 0;
    read.textContent = active ? 'chamber' : 'rest';
    B.wake();
  }
  bag.add(pointer(stage, { move: respond, down: respond, leave: () => respond(null) }));
  bag.add(() => svg.replaceChildren());
  draw();
  return { set: v => { reach = v; if (active) respond([335,160]); }, reset: () => respond(null), destroy: bag.dispose };
}

hairline({
  name: 'isolation',
  means: 'A chip inside an isolation chamber: the pointer lifts its cover and lets the chip rise within the enclosure.',
  rules: [1, 3, 5, 7, 8, 9, 10],
  range: [18, 32, 48],
  mount,
});

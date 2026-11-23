import { gsap } from 'gsap'
import { onBeforeUnmount, onMounted, type Ref } from 'vue'

/**
 * Motion toolkit built on GSAP. Every effect honours prefers-reduced-motion:
 * with reduced motion, elements jump straight to their final state.
 */
export function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

type Targets = gsap.TweenTarget

/** Staggered rise-in for lists, cards and table rows. */
export function revealStagger(targets: Targets, opts: { y?: number; stagger?: number; delay?: number; duration?: number } = {}) {
  if (prefersReducedMotion()) return gsap.set(targets, { opacity: 1, y: 0, clearProps: 'transform' })
  return gsap.fromTo(
    targets,
    { opacity: 0, y: opts.y ?? 14, filter: 'blur(4px)' },
    {
      opacity: 1, y: 0, filter: 'blur(0px)',
      duration: opts.duration ?? 0.55,
      ease: 'power3.out',
      stagger: opts.stagger ?? 0.05,
      delay: opts.delay ?? 0,
      clearProps: 'filter,transform',
    },
  )
}

/** Animated count-up for KPI numbers. */
export function countUp(el: HTMLElement, to: number, format: (n: number) => string, duration = 1.1) {
  if (prefersReducedMotion()) {
    el.textContent = format(to)
    return
  }
  const state = { v: 0 }
  gsap.to(state, {
    v: to,
    duration,
    ease: 'power2.out',
    onUpdate: () => { el.textContent = format(state.v) },
  })
}

/** Draws an SVG path from start to end (charts, check marks). */
export function drawPath(path: SVGPathElement, duration = 1.2, delay = 0) {
  const len = path.getTotalLength()
  if (prefersReducedMotion()) return gsap.set(path, { strokeDasharray: 'none', strokeDashoffset: 0 })
  return gsap.fromTo(path, { strokeDasharray: len, strokeDashoffset: len }, { strokeDashoffset: 0, duration, delay, ease: 'power2.inOut' })
}

/** Small pop used when a value changes (cart total, badge). */
export function pop(el: Element | null) {
  if (!el || prefersReducedMotion()) return
  gsap.fromTo(el, { scale: 1 }, { scale: 1.08, duration: 0.12, yoyo: true, repeat: 1, ease: 'power1.out' })
}

/** Shake for validation errors / blocked actions (e.g. expired batch). */
export function shake(el: Element | null) {
  if (!el || prefersReducedMotion()) return
  gsap.fromTo(el, { x: 0 }, { keyframes: { x: [-8, 8, -6, 6, -3, 0] }, duration: 0.45, ease: 'power1.out' })
}

/**
 * "Fly to cart": clones a small dot from the source rect and arcs it into the
 * target element, then pulses the target. Premium feedback for add-to-cart.
 */
export function flyTo(from: DOMRect, target: HTMLElement | null, color = '#0e9f8f') {
  if (!target || prefersReducedMotion()) return
  const to = target.getBoundingClientRect()
  const dot = document.createElement('div')
  Object.assign(dot.style, {
    position: 'fixed', left: `${from.left + from.width / 2 - 9}px`, top: `${from.top + from.height / 2 - 9}px`,
    width: '18px', height: '18px', borderRadius: '9999px', background: color, zIndex: '9999',
    boxShadow: `0 0 0 6px ${color}33, 0 8px 24px ${color}88`, pointerEvents: 'none',
  })
  document.body.appendChild(dot)
  const dx = to.left + to.width / 2 - (from.left + from.width / 2)
  const dy = to.top + 24 - (from.top + from.height / 2)
  gsap.timeline({ onComplete: () => dot.remove() })
    .to(dot, { x: dx, duration: 0.65, ease: 'power1.inOut' }, 0)
    .to(dot, { y: dy, duration: 0.65, ease: 'back.in(1.6)' }, 0)
    .to(dot, { scale: 0.3, opacity: 0.2, duration: 0.2 }, 0.48)
    .add(() => pop(target), 0.62)
}

/** Subtle magnetic pull toward the pointer for primary CTAs. */
export function useMagnetic(elRef: Ref<HTMLElement | null>, strength = 0.25) {
  let xTo: gsap.QuickToFunc | null = null
  let yTo: gsap.QuickToFunc | null = null
  const onMove = (e: PointerEvent) => {
    const el = elRef.value
    if (!el || !xTo || !yTo) return
    const r = el.getBoundingClientRect()
    xTo((e.clientX - (r.left + r.width / 2)) * strength)
    yTo((e.clientY - (r.top + r.height / 2)) * strength)
  }
  const onLeave = () => { xTo?.(0); yTo?.(0) }
  onMounted(() => {
    const el = elRef.value
    if (!el || prefersReducedMotion() || window.matchMedia('(pointer: coarse)').matches) return
    xTo = gsap.quickTo(el, 'x', { duration: 0.4, ease: 'power3.out' })
    yTo = gsap.quickTo(el, 'y', { duration: 0.4, ease: 'power3.out' })
    el.addEventListener('pointermove', onMove)
    el.addEventListener('pointerleave', onLeave)
  })
  onBeforeUnmount(() => {
    elRef.value?.removeEventListener('pointermove', onMove)
    elRef.value?.removeEventListener('pointerleave', onLeave)
  })
}

/** Pointer-tracking spotlight for cards (pairs with the .spotlight CSS class). */
export function useSpotlight(elRef: Ref<HTMLElement | null>) {
  const onMove = (e: PointerEvent) => {
    const el = elRef.value
    if (!el) return
    const r = el.getBoundingClientRect()
    el.style.setProperty('--mx', `${e.clientX - r.left}px`)
    el.style.setProperty('--my', `${e.clientY - r.top}px`)
  }
  onMounted(() => elRef.value?.addEventListener('pointermove', onMove))
  onBeforeUnmount(() => elRef.value?.removeEventListener('pointermove', onMove))
}

/** Page transition hooks for <NuxtPage :transition>. */
export const pageTransition = {
  name: 'page',
  mode: 'out-in' as const,
  css: false,
  onEnter(el: Element, done: () => void) {
    if (prefersReducedMotion()) return done()
    gsap.fromTo(el, { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.35, ease: 'power2.out', onComplete: done, clearProps: 'transform' })
  },
  onLeave(el: Element, done: () => void) {
    if (prefersReducedMotion()) return done()
    gsap.to(el, { opacity: 0, y: -6, duration: 0.18, ease: 'power1.in', onComplete: done })
  },
}

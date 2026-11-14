import { Injectable, inject } from '@angular/core';
import { OverlayContainer } from '@angular/cdk/overlay';

@Injectable({ providedIn: 'root' })
export class DolibarrThemeService {
  private overlay = inject(OverlayContainer);

  apply(): void {
    const host = document.querySelector<HTMLElement>('owt-root');
    if (!host) return;
    // Measure native Dolibarr controls. Never change Dolibarr's document-level theme.
    const sample = document.createElement('div');
    sample.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none;';
    const button = document.createElement('button');
    button.className = 'butAction';
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'flat';
    const success = document.createElement('span');
    success.className = 'ok';
    const error = document.createElement('span');
    error.className = 'error';
    sample.append(button, input, success, error);
    (host.parentElement || document.body).append(sample);
    const nativeButton = getComputedStyle(button);
    const nativeInput = getComputedStyle(input);
    const text = getComputedStyle(host).color;
    let surface = '';
    for (
      let ancestor: HTMLElement | null = host.parentElement;
      ancestor;
      ancestor = ancestor.parentElement
    ) {
      const background = getComputedStyle(ancestor).backgroundColor;
      if (background !== 'rgba(0, 0, 0, 0)' && background !== 'transparent') {
        surface = background;
        break;
      }
    }
    if (!surface) {
      const background = getComputedStyle(document.documentElement).backgroundColor;
      surface =
        background !== 'rgba(0, 0, 0, 0)' && background !== 'transparent' ? background : 'Canvas';
    }
    const values: Record<string, string> = {
      '--owt-text': text,
      '--owt-surface': surface,
      '--owt-action-bg': nativeButton.backgroundColor,
      '--owt-action-background': nativeButton.background,
      '--owt-action-text': nativeButton.color,
      '--owt-font': getComputedStyle(host).fontFamily,
      '--owt-font-size': getComputedStyle(host).fontSize,
      '--owt-input-bg': nativeInput.backgroundColor,
      '--owt-input-background': nativeInput.background,
      '--owt-success-color': getComputedStyle(success).color,
      '--owt-error-color': getComputedStyle(error).color,
    };
    sample.remove();
    for (const target of [host, this.overlay.getContainerElement()]) {
      target.classList.add('owt-theme');
      for (const [key, value] of Object.entries(values)) target.style.setProperty(key, value);
    }
  }
}

import { describe, expect, it } from 'vitest';
import { ToastService } from './toast.service';

describe('ToastService', () => {
  it('adds and removes toasts', () => {
    const service = new ToastService();
    expect(service.toasts().length).toBe(0);

    const id1 = service.success('All good!');
    expect(service.toasts().length).toBe(1);
    expect(service.toasts()[0].message).toBe('All good!');
    expect(service.toasts()[0].type).toBe('success');

    const id2 = service.error('Something broke');
    expect(service.toasts().length).toBe(2);

    service.remove(id1);
    expect(service.toasts().length).toBe(1);
    expect(service.toasts()[0].id).toBe(id2);
  });
});

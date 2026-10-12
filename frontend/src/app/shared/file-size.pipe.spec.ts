import { describe, expect, it } from 'vitest';
import { FileSizePipe } from './file-size.pipe';

describe('FileSizePipe', () => {
  const pipe = new FileSizePipe();

  it('formats bytes under 1 KB', () => {
    expect(pipe.transform(500)).toBe('500 B');
  });

  it('formats kilobytes', () => {
    expect(pipe.transform(2048)).toBe('2.0 KB');
  });

  it('formats megabytes', () => {
    expect(pipe.transform(5242880)).toBe('5.0 MB');
  });

  it('handles zero, null, and undefined gracefully', () => {
    expect(pipe.transform(0)).toBe('0 B');
    expect(pipe.transform(null)).toBe('—');
    expect(pipe.transform(undefined)).toBe('—');
  });
});

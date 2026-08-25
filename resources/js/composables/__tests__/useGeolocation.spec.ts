import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { useGeolocation } from '@/composables/useGeolocation';

vi.mock('@/lib/api', () => ({
    get: vi.fn(),
}));

function mountComposable(setLocation = vi.fn()) {
    let result: ReturnType<typeof useGeolocation> | null = null;
    const wrapper = mount(
        defineComponent({
            setup() {
                result = useGeolocation(setLocation);
                return () => h('div');
            },
        }),
    );
    return { wrapper, result: result! };
}

describe('useGeolocation', () => {
    let origGeolocation: typeof navigator.geolocation;

    beforeEach(() => {
        origGeolocation = navigator.geolocation;
    });

    afterEach(() => {
        vi.restoreAllMocks();
        Object.defineProperty(navigator, 'geolocation', {
            value: origGeolocation,
            writable: true,
            configurable: true,
        });
    });

    describe('initial state', () => {
        it('returns lat and lng initialized to null', () => {
            const { result } = mountComposable();
            expect(result.lat.value).toBeNull();
            expect(result.lng.value).toBeNull();
        });

        it('returns location initialized to null city/state', () => {
            const { result } = mountComposable();
            expect(result.location.value).toEqual({ city: null, state: null });
        });

        it('returns detectingLocation initialized to false', () => {
            const { result } = mountComposable();
            expect(result.detectingLocation.value).toBe(false);
        });

        it('returns geolocationError initialized to null', () => {
            const { result } = mountComposable();
            expect(result.geolocationError.value).toBeNull();
        });

        it('exposes detectLocation as a function', () => {
            const { result } = mountComposable();
            expect(typeof result.detectLocation).toBe('function');
        });
    });

    describe('when geolocation is unavailable', () => {
        beforeEach(() => {
            delete (navigator as any).geolocation;
        });

        it('returns early without error when detectLocation is called', async () => {
            const { result } = mountComposable();
            await result.detectLocation();
            expect(result.detectingLocation.value).toBe(false);
            expect(result.geolocationError.value).toBeNull();
        });
    });

    describe('geolocation success', () => {
        let fireSuccess: (position: GeolocationPosition) => void;
        let getCurrentPosition: ReturnType<typeof vi.fn>;

        function mockGeolocation() {
            let storedSuccess: PositionCallback | null = null;
            let _storedError: PositionErrorCallback | null = null;

            const _getCurrentPosition = vi.fn().mockImplementation(
                (success: PositionCallback, error: PositionErrorCallback) => {
                    storedSuccess = success;
                    _storedError = error;
                },
            );

            Object.defineProperty(navigator, 'geolocation', {
                value: { getCurrentPosition: _getCurrentPosition },
                writable: true,
                configurable: true,
            });

            getCurrentPosition = _getCurrentPosition;
            fireSuccess = (position: GeolocationPosition) => {
                storedSuccess?.(position);
            };
        }

        beforeEach(() => {
            mockGeolocation();
        });

        it('sets detectingLocation to true while detecting', () => {
            const { result } = mountComposable();
            result.detectLocation();
            expect(result.detectingLocation.value).toBe(true);
        });

        it('sets lat and lng on GPS success', async () => {
            const { result } = mountComposable();
            result.detectLocation();
            fireSuccess({ coords: { latitude: 40.7, longitude: -74.0 } } as GeolocationPosition);
            // Wait for the async reverse-geocode callback to settle
            await vi.waitFor(() => {
                expect(result.lat.value).toBe(40.7);
                expect(result.lng.value).toBe(-74.0);
            });
        });

        it('sets detectingLocation to false after GPS success', async () => {
            const { result } = mountComposable();
            result.detectLocation();
            fireSuccess({ coords: { latitude: 40.7, longitude: -74.0 } } as GeolocationPosition);
            await vi.waitFor(() => {
                expect(result.detectingLocation.value).toBe(false);
            });
        });

        it('reverse geocodes and sets location on success', async () => {
            const { get } = await import('@/lib/api');
            vi.mocked(get).mockResolvedValueOnce({ city: 'New York', state: 'NY' });

            const setLocation = vi.fn();
            const { result } = mountComposable(setLocation);

            result.detectLocation();
            fireSuccess({ coords: { latitude: 40.7, longitude: -74.0 } } as GeolocationPosition);

            await vi.waitFor(() => {
                expect(result.location.value).toEqual({ city: 'New York', state: 'NY' });
            });
            expect(setLocation).toHaveBeenCalledWith('New York', 'NY', 40.7, -74.0);
        });

        it('handles reverse geocode failure gracefully', async () => {
            const { get } = await import('@/lib/api');
            vi.mocked(get).mockRejectedValueOnce(new Error('Network error'));

            const setLocation = vi.fn();
            const { result } = mountComposable(setLocation);

            result.detectLocation();
            fireSuccess({ coords: { latitude: 40.7, longitude: -74.0 } } as GeolocationPosition);

            await vi.waitFor(() => {
                expect(result.detectingLocation.value).toBe(false);
            });
            expect(result.lat.value).toBe(40.7);
            expect(result.lng.value).toBe(-74.0);
            expect(result.location.value).toEqual({ city: null, state: null });
            expect(setLocation).not.toHaveBeenCalled();
        });

        it('skips setLocation when geocode returns empty result', async () => {
            const { get } = await import('@/lib/api');
            vi.mocked(get).mockResolvedValueOnce({});

            const setLocation = vi.fn();
            const { result } = mountComposable(setLocation);

            result.detectLocation();
            fireSuccess({ coords: { latitude: 40.7, longitude: -74.0 } } as GeolocationPosition);

            await vi.waitFor(() => {
                expect(result.detectingLocation.value).toBe(false);
            });
            expect(result.location.value).toEqual({ city: null, state: null });
            expect(setLocation).not.toHaveBeenCalled();
        });

        it('passes timeout and accuracy options to getCurrentPosition', () => {
            mountComposable().result.detectLocation();
            expect(getCurrentPosition).toHaveBeenCalledTimes(1);
            const [_ok, _err, opts] = getCurrentPosition.mock.calls[0];
            expect(opts).toEqual({ timeout: 10000, enableHighAccuracy: false });
        });
    });

    describe('geolocation error', () => {
        let fireError: () => void;
        let getCurrentPosition: ReturnType<typeof vi.fn>;

        function mockErrorGeolocation() {
            let _storedSuccess: PositionCallback | null = null;
            let storedError: PositionErrorCallback | null = null;

            const _getCurrentPosition = vi.fn().mockImplementation(
                (success: PositionCallback, error: PositionErrorCallback) => {
                    _storedSuccess = success;
                    storedError = error;
                },
            );

            Object.defineProperty(navigator, 'geolocation', {
                value: { getCurrentPosition: _getCurrentPosition },
                writable: true,
                configurable: true,
            });

            getCurrentPosition = _getCurrentPosition;
            fireError = () => {
                storedError?.({} as GeolocationPositionError);
            };
        }

        beforeEach(() => {
            mockErrorGeolocation();
        });

        it('sets geolocationError message on GPS error', () => {
            const { result } = mountComposable();
            result.detectLocation();
            fireError();
            expect(result.geolocationError.value).toBe('Unable to detect your location. Please enter it manually.');
        });

        it('sets detectingLocation to false after GPS error', () => {
            const { result } = mountComposable();
            result.detectLocation();
            fireError();
            expect(result.detectingLocation.value).toBe(false);
        });
    });
});

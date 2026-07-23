import { ref, computed } from 'vue';
import type { Restaurant } from '@/types/restaurant';

const STORAGE_KEY = 'ipop360_compare_ids';

function loadIds(): number[] {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        return raw ? JSON.parse(raw) : [];
    } catch {
        return [];
    }
}

function saveIds(ids: number[]) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
}

const compareIds = ref<number[]>(loadIds());

export function useCompare() {
    const count = computed(() => compareIds.value.length);

    const isInCompare = (restaurant: Restaurant): boolean => {
        return compareIds.value.includes(restaurant.id);
    };

    const toggleCompare = (restaurant: Restaurant) => {
        const id = restaurant.id;
        if (compareIds.value.includes(id)) {
            compareIds.value = compareIds.value.filter(i => i !== id);
        } else {
            compareIds.value = [...compareIds.value, id];
        }
        saveIds(compareIds.value);
    };

    const clearCompare = () => {
        compareIds.value = [];
        saveIds([]);
    };

    const compareUrl = computed(() => {
        if (compareIds.value.length === 0) return null;
        return `/compare?ids=${compareIds.value.join(',')}`;
    });

    return {
        compareIds,
        count,
        isInCompare,
        toggleCompare,
        clearCompare,
        compareUrl,
    };
}

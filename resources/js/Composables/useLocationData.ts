import { ref } from 'vue'
import axios from 'axios'

export function useLocationData() {
    const states = ref<{ id: number; name: string }[]>([])
    const cities = ref<{ id: number; name: string }[]>([])
    const loadingStates = ref(false)
    const loadingCities = ref(false)
    const locationError = ref<string | null>(null)

    const extractArray = (data: any): { id: number; name: string }[] => {
        if (Array.isArray(data)) return data
        if (data?.data && Array.isArray(data.data)) return data.data
        return []
    }

    const fetchStates = async (countryId: number | string) => {
        states.value = []
        cities.value = []
        locationError.value = null

        if (!countryId) return

        loadingStates.value = true
        try {
            const { data } = await axios.get(route('api.states'), {
                params: { country_id: countryId },
            })
            states.value = extractArray(data)
        } catch {
            locationError.value = 'Erro ao carregar estados. Por favor tente novamente.'
        } finally {
            loadingStates.value = false
        }
    }

    const fetchCities = async (stateId: number | string) => {
        cities.value = []
        locationError.value = null

        if (!stateId) return

        loadingCities.value = true
        try {
            const { data } = await axios.get(route('api.cities'), {
                params: { state_id: stateId },
            })
            cities.value = extractArray(data)
        } catch {
            locationError.value = 'Erro ao carregar cidades. Por favor tente novamente.'
        } finally {
            loadingCities.value = false
        }
    }

    return {
        states,
        cities,
        loadingStates,
        loadingCities,
        locationError,
        fetchStates,
        fetchCities,
    }
}
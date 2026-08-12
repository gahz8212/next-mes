// src/store/useMesStore.ts
import { create } from 'zustand';

interface MesState {
  productionCount: number;
  barcodeHistory: string[];
  incrementProduction: () => void;
  addHistory: (barcode: string) => void;
}

export const useMesStore = create<MesState>((set) => ({
  productionCount: 0,
  barcodeHistory: [],
  incrementProduction: () => set((state) => ({ productionCount: state.productionCount + 1 })),
  addHistory: (barcode) => set((state) => ({ barcodeHistory: [barcode, ...state.barcodeHistory] })),
}));
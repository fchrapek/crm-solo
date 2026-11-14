import { createContext, type ReactNode, useContext } from 'react';

/** row: the earlier pill row; menu: the phone "more" menu; frame: the redesign's record frame, where an action is a control button. */
export type HeaderPlace = 'row' | 'menu' | 'frame';

const HeaderPlaceContext = createContext<HeaderPlace>('row');

/** True inside the phone "more" menu, where a header action renders as a menu item. */
export function useInHeaderMenu(): boolean {
    return useContext(HeaderPlaceContext) === 'menu';
}

/** True in the record frame's button row. */
export function useInRecordFrame(): boolean {
    return useContext(HeaderPlaceContext) === 'frame';
}

export function HeaderActionsScope({ place, children }: { place: HeaderPlace; children: ReactNode }) {
    return <HeaderPlaceContext.Provider value={place}>{children}</HeaderPlaceContext.Provider>;
}

export function useHeaderPlace(): HeaderPlace {
    return useContext(HeaderPlaceContext);
}

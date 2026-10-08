# Portal menu search

The portal sidebar has a "Search menu" box above the menu groups. It is in the desktop sidebar and in the mobile menu drawer. There is no API call. It filters the menu items the user can already see (`PortalSidebarNav#canSeeNavItem`), so it never shows a screen the user's permissions hide.

Typing filters items by their label and their group label, ignoring case. Each word must match. Groups with no matching item are hidden. When nothing matches, the menu shows "No menu items match …".

Keys in the box:
- Enter opens the first matching item and clears the search.
- Escape clears the search, or leaves the box when it is already empty.

Ctrl+K (⌘K on Mac) works from any portal page except the POS terminal (`/pos`) and the Kitchen Display (`/kitchen-display`). It opens the desktop sidebar if it is collapsed, or the menu drawer on a narrow screen, and puts the cursor in the search box. The box shows the shortcut as a hint while empty. Clicking a menu item also clears the search.

The sidebar keeps the current page's item in view. When the sidebar or drawer opens, after a page change, and when the search clears, it scrolls only if that item is out of sight, and centres it.

## Not included

Search covers menu labels only. It does not search bookings, guests, rooms or other records, and it does not match keywords or synonyms that are not in the label.

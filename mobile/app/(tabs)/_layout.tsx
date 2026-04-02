import { HapticTab } from '@/components/haptic-tab';
import { IconSymbol } from '@/components/ui/icon-symbol';
import { Colors } from '@/constants/theme';
import { useColorScheme } from '@/hooks/use-color-scheme';
import { StorageService, UserRole } from '@/scripts/storage';
import { Tabs } from 'expo-router';
import React, { useEffect, useState } from 'react';

export default function TabLayout() {
  const colorScheme = useColorScheme();
  const [role, setRole] = useState<UserRole | null>(null);

  useEffect(() => {
    let lastUserId: string | null = null;
    let isMounted = true;

    const checkRole = async () => {
      const id = await StorageService.getCurrentUser();
      
      // Only fetch everything if the logged-in user actually changed
      if (id !== lastUserId) {
        lastUserId = id;
        if (id) {
          const emps = await StorageService.getEmployees();
          const me = emps.find(e => e.id === id);
          if (me && isMounted) setRole(me.role);
        } else {
          if (isMounted) setRole(null);
        }
      }
    };

    checkRole();
    const interval = setInterval(checkRole, 1500);

    return () => {
      isMounted = false;
      clearInterval(interval);
    };
  }, []);

  const isAdmin = role === 'admin' || role === 'superadmin';

  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: Colors[colorScheme ?? 'light'].tint,
        headerShown: false,
        tabBarButton: HapticTab,
      }}>
      <Tabs.Screen
        name="index"
        options={{
          title: 'Davomat',
          tabBarIcon: ({ color }) => <IconSymbol size={28} name="calendar.badge.clock" color={color} />,
        }}
      />
      <Tabs.Screen
        name="explore"
        options={{
          title: 'Hisobotlar',
          tabBarIcon: ({ color }) => <IconSymbol size={28} name="chart.bar.fill" color={color} />,
        }}
      />
      <Tabs.Screen
        name="tasks"
        options={{
          title: 'Vazifalar',
          tabBarIcon: ({ color }) => <IconSymbol size={28} name="checklist" color={color} />,
        }}
      />
      <Tabs.Screen
        name="contacts"
        options={{
          title: 'Jamoa',
          tabBarIcon: ({ color }) => <IconSymbol size={28} name="person.2.fill" color={color} />,
        }}
      />
      <Tabs.Screen
        name="admin"
        options={{
          title: 'Admin',
          tabBarIcon: ({ color }) => <IconSymbol size={28} name="shield.fill" color={color} />,
          href: isAdmin ? undefined : null, // ROLE PROTECTION: Hide for non-admins
        }}
      />
    </Tabs>
  );
}

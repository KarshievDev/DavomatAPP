import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import Constants from 'expo-constants';
import { Platform } from 'react-native';
import { Employee, StorageService } from './storage';
import { getTimeMinutes } from './dateUtils';

export async function registerForPushNotificationsAsync() {
  let token;

  if (Platform.OS === 'web') {
    return null;
  }

  if (Device.isDevice) {
    const { status: existingStatus } = await Notifications.getPermissionsAsync();
    let finalStatus = existingStatus;
    if (existingStatus !== 'granted') {
      const { status } = await Notifications.requestPermissionsAsync();
      finalStatus = status;
    }
    if (finalStatus !== 'granted') {
      console.log('Failed to get push token for push notification!');
      return null;
    }
    
    const projectId = Constants?.expoConfig?.extra?.eas?.projectId ?? Constants?.easConfig?.projectId;
    
    token = (await Notifications.getExpoPushTokenAsync({
       projectId 
    })).data;
  }

  if (Platform.OS === 'android') {
    Notifications.setNotificationChannelAsync('default', {
      name: 'default',
      importance: Notifications.AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#FF231F7C',
    });
  }

  return token;
}

export async function initNotifications(employee: Employee) {
    try {
        const token = await registerForPushNotificationsAsync();
        if (token) {
            await StorageService.savePushToken(employee.id, token);
        }
        
        // Schedule local reminders
        await scheduleWorkReminders(employee);
    } catch (error) {
        console.error('Notification init error:', error);
    }
}

export async function scheduleWorkReminders(employee: Employee) {
  if (Platform.OS === 'web') return;

  // Cancel all existing scheduled notifications to avoid duplicates
  await Notifications.cancelAllScheduledNotificationsAsync();

  const startMins = getTimeMinutes(employee.workStartTime);
  const endMins = getTimeMinutes(employee.workEndTime);

  const dayInMins = 24 * 60;

  // 10 mins before start
  const startNotify = minsToHM(startMins - 10);
  await Notifications.scheduleNotificationAsync({
    content: {
      title: "Ish vaqti yaqinlashmoqda!",
      body: "10 daqiqadan so'ng ish vaqtingiz boshlanadi. 'Kirib keldim' tugmasini bosish esingizdan chiqmasin!",
      sound: true,
    },
    trigger: {
      type: Notifications.SchedulableTriggerInputTypes.CALENDAR,
      hour: startNotify.hour,
      minute: startNotify.minute,
      repeats: true,
    },
  });

  // 10 mins before end
  const endNotify = minsToHM(endMins - 10);
  await Notifications.scheduleNotificationAsync({
    content: {
      title: "Ish vaqti yakunlanmoqda!",
      body: "10 daqiqadan so'ng ish vaqtingiz yakunlanadi. 'Chiqish' tugmasini bosish esingizdan chiqmasin!",
      sound: true,
    },
    trigger: {
      type: Notifications.SchedulableTriggerInputTypes.CALENDAR,
      hour: endNotify.hour,
      minute: endNotify.minute,
      repeats: true,
    },
  });
}

function minsToHM(totalMins: number) {
  let relativeMins = totalMins;
  const dayInMins = 24 * 60;
  
  while (relativeMins < 0) relativeMins += dayInMins;
  while (relativeMins >= dayInMins) relativeMins -= dayInMins;

  return {
    hour: Math.floor(relativeMins / 60),
    minute: relativeMins % 60
  };
}

import { Task, StorageService, Employee } from '@/scripts/storage';
import { Colors } from '@/constants/theme';
import { useColorScheme } from '@/hooks/use-color-scheme';
import { CheckCircle2, Circle, Clock, AlertCircle, RefreshCw } from 'lucide-react-native';
import React, { useEffect, useState, useCallback } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, TouchableOpacity, View, ActivityIndicator } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useFocusEffect } from '@react-navigation/native';

export default function TasksScreen() {
    const insets = useSafeAreaInsets();
    const colorScheme = useColorScheme();
    const theme = Colors[colorScheme ?? 'light'];
    const [tasks, setTasks] = useState<Task[]>([]);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [currentUser, setCurrentUser] = useState<Employee | null>(null);

    const fetchTasks = async (force = false) => {
        const userId = await StorageService.getCurrentUser();
        if (userId) {
            const emps = await StorageService.getEmployees();
            const me = emps.find(e => e.id === userId);
            setCurrentUser(me || null);

            const fetchedTasks = await StorageService.getTasks(userId, force);
            setTasks(fetchedTasks);
        }
        setLoading(false);
        setRefreshing(false);
    };

    useFocusEffect(
        useCallback(() => {
            fetchTasks();
        }, [])
    );

    const toggleStatus = async (task: Task) => {
        let nextStatus: Task['status'] = 'pending';
        if (task.status === 'pending') nextStatus = 'in_progress';
        else if (task.status === 'in_progress') nextStatus = 'completed';
        else if (task.status === 'completed') nextStatus = 'pending';

        const res = await StorageService.updateTaskStatus(task.id, nextStatus);
        if (res.success) {
            setTasks(tasks.map(t => t.id === task.id ? { ...t, status: nextStatus } : t));
        }
    };

    const renderTask = ({ item }: { item: Task }) => {
        const isCompleted = item.status === 'completed';
        const isInProgress = item.status === 'in_progress';
        
        return (
            <TouchableOpacity 
                style={[styles.taskCard, { backgroundColor: theme.background, borderColor: isCompleted ? '#10B98120' : '#E2E8F0' }]}
                onPress={() => toggleStatus(item)}
                activeOpacity={0.7}
            >
                <View style={styles.taskHeader}>
                    <View style={styles.statusRow}>
                        {isCompleted ? (
                            <CheckCircle2 size={22} color="#10B981" />
                        ) : isInProgress ? (
                            <ActivityIndicator size="small" color="#3B82F6" style={{ marginRight: 4 }} />
                        ) : (
                            <Circle size={22} color="#94A3B8" />
                        )}
                        <Text style={[styles.taskTitle, { color: theme.text, textDecorationLine: isCompleted ? 'line-through' : 'none', opacity: isCompleted ? 0.6 : 1 }]}>
                            {item.title}
                        </Text>
                    </View>
                    <View style={[styles.badge, { backgroundColor: isCompleted ? '#10B98115' : isInProgress ? '#3B82F615' : '#F59E0B15' }]}>
                        <Text style={[styles.badgeText, { color: isCompleted ? '#10B981' : isInProgress ? '#3B82F6' : '#F59E0B' }]}>
                            {item.status === 'pending' ? 'Kutilmoqda' : item.status === 'in_progress' ? 'Jarayonda' : item.status === 'completed' ? 'Bajarildi' : 'Bekor qilindi'}
                        </Text>
                    </View>
                </View>

                {item.description ? (
                    <Text style={[styles.taskDesc, { color: theme.text, opacity: 0.6, marginBottom: 0 }]}>
                        {item.description}
                    </Text>
                ) : null}

            </TouchableOpacity>
        );
    };

    if (loading) {
        return (
            <View style={[styles.container, { paddingTop: insets.top, justifyContent: 'center' }]}>
                <ActivityIndicator size="large" color="#3B82F6" />
                <Text style={styles.loadingText}>Vazifalar yuklanmoqda...</Text>
            </View>
        );
    }

    return (
        <View style={[styles.container, { paddingTop: insets.top, backgroundColor: theme.background }]}>
            <View style={styles.header}>
                <View>
                    <Text style={[styles.greeting, { color: theme.text, opacity: 0.5 }]}>Salom, {currentUser?.fullName.split(' ')[0]}</Text>
                    <Text style={[styles.title, { color: theme.text }]}>Vazifalarim</Text>
                </View>
                <TouchableOpacity onPress={() => fetchTasks(true)} style={styles.refreshBtn}>
                    <RefreshCw size={20} color="#3B82F6" />
                </TouchableOpacity>
            </View>

            {tasks.length === 0 ? (
                <View style={styles.emptyContainer}>
                    <AlertCircle size={60} color="#CBD5E1" strokeWidth={1} />
                    <Text style={styles.emptyTitle}>Vazifalar topilmadi</Text>
                    <Text style={styles.emptySubtitle}>Sizga hozircha vazifa tayinlanmagan.</Text>
                </View>
            ) : (
                <FlatList
                    data={tasks}
                    renderItem={renderTask}
                    keyExtractor={item => item.id}
                    contentContainerStyle={styles.listContent}
                    refreshControl={
                        <RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); fetchTasks(true); }} tintColor="#3B82F6" />
                    }
                />
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
    },
    header: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingHorizontal: 24,
        paddingVertical: 20,
    },
    greeting: {
        fontSize: 12,
        fontWeight: '900',
        textTransform: 'uppercase',
        letterSpacing: 1,
    },
    title: {
        fontSize: 32,
        fontWeight: '900',
    },
    refreshBtn: {
        width: 44,
        height: 44,
        borderRadius: 15,
        backgroundColor: '#3B82F610',
        justifyContent: 'center',
        alignItems: 'center',
    },
    listContent: {
        padding: 24,
        paddingTop: 10,
    },
    taskCard: {
        borderRadius: 24,
        padding: 20,
        marginBottom: 16,
        borderWidth: 1,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.05,
        shadowRadius: 10,
        elevation: 2,
    },
    taskHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'flex-start',
        marginBottom: 12,
    },
    statusRow: {
        flexDirection: 'row',
        alignItems: 'center',
        flex: 1,
        marginRight: 10,
    },
    taskTitle: {
        fontSize: 17,
        fontWeight: '800',
        marginLeft: 12,
        flex: 1,
    },
    badge: {
        paddingHorizontal: 10,
        paddingVertical: 5,
        borderRadius: 10,
    },
    badgeText: {
        fontSize: 9,
        fontWeight: '900',
        textTransform: 'uppercase',
    },
    taskDesc: {
        fontSize: 14,
        lineHeight: 20,
        fontWeight: '500',
        marginBottom: 16,
        marginLeft: 34,
    },
    emptyContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        paddingBottom: 100,
    },
    emptyTitle: {
        fontSize: 18,
        fontWeight: '900',
        color: '#475569',
        marginTop: 16,
    },
    emptySubtitle: {
        fontSize: 14,
        color: '#94A3B8',
        fontWeight: '600',
        marginTop: 4,
    },
    loadingText: {
        marginTop: 12,
        fontSize: 14,
        fontWeight: '700',
        color: '#3B82F6',
        textAlign: 'center',
    }
});

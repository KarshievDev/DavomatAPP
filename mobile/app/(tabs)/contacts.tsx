import { Branch, Employee, StorageService } from '@/scripts/storage';
import { Phone, Search, Users } from 'lucide-react-native';
import React, { useEffect, useState } from 'react';
import { FlatList, Keyboard, Linking, RefreshControl, StyleSheet, Text, TextInput, TouchableOpacity, TouchableWithoutFeedback, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export default function ContactsScreen() {
    const insets = useSafeAreaInsets();
    const [employees, setEmployees] = useState<Employee[]>([]);
    const [branches, setBranches] = useState<Branch[]>([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [loading, setLoading] = useState(true);

    const fetchData = async (force = false) => {
        try {
            const [emps, brs] = await Promise.all([
                StorageService.getEmployees(force),
                StorageService.getBranches(force)
            ]);
            setEmployees(emps);
            setBranches(brs);
        } catch (error) {
            console.error('Error fetching contacts:', error);
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, []);

    const filteredEmployees = employees.filter(emp =>
        emp.fullName.toLowerCase().includes(searchQuery.toLowerCase()) ||
        emp.position.toLowerCase().includes(searchQuery.toLowerCase()) ||
        emp.phone.includes(searchQuery)
    );

    const getBranchName = (branchId: string) => {
        return branches.find(b => b.id === branchId)?.name || 'Noma\'lum filial';
    };

    const handleCall = (phone: string) => {
        Linking.openURL(`tel:${phone.replace(/\s/g, '')}`);
    };

    const renderEmployeeItem = React.useCallback(({ item }: { item: Employee }) => (
        <View style={styles.card}>
            <View style={styles.avatarContainer}>
                <Text style={styles.avatarText}>{item.fullName.charAt(0).toUpperCase()}</Text>
            </View>
            <View style={styles.infoContainer}>
                <Text style={styles.nameText}>{item.fullName}</Text>
                <Text style={styles.positionText}>{item.position}</Text>
                <View style={styles.branchBadge}>
                    <Text style={styles.branchText}>{getBranchName(item.branchId)}</Text>
                </View>
                <TouchableOpacity
                    style={styles.phoneContainer}
                    onPress={() => handleCall(item.phone)}
                >
                    <Phone size={16} color="#3B82F6" />
                    <Text style={styles.phoneText}>{item.phone}</Text>
                </TouchableOpacity>
            </View>
            <TouchableOpacity
                style={styles.callButton}
                onPress={() => handleCall(item.phone)}
            >
                <Phone size={24} color="#FFF" />
            </TouchableOpacity>
        </View>
    ), [branches]);

    return (
        <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
            <View style={styles.container}>
                <View style={[styles.header, { paddingTop: (insets.top || 0) + 20 }]}>
                    <Text style={styles.title}>Xodimlar</Text>
                    <View style={styles.searchContainer}>
                        <Search size={20} color="#6B7280" style={styles.searchIcon} />
                        <TextInput
                            style={styles.searchInput}
                            placeholder="Qidirish..."
                            value={searchQuery}
                            onChangeText={setSearchQuery}
                            returnKeyType="search"
                            clearButtonMode="while-editing"
                        />
                    </View>
                </View>

                <FlatList
                    data={filteredEmployees}
                    keyExtractor={(item) => item.id}
                    renderItem={renderEmployeeItem}
                    contentContainerStyle={styles.listContent}
                    initialNumToRender={10}
                    maxToRenderPerBatch={10}
                    windowSize={5}
                    removeClippedSubviews={true}
                    keyboardShouldPersistTaps="handled"
                    refreshControl={
                        <RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); fetchData(true); }} tintColor="#3B82F6" />
                    }
                    ListEmptyComponent={
                        <View style={styles.emptyContainer}>
                            <Users size={48} color="#D1D5DB" />
                            <Text style={styles.emptyText}>Xodimlar topilmadi</Text>
                        </View>
                    }
                />
            </View>
        </TouchableWithoutFeedback>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#F3F4F6',
    },
    header: {
        paddingHorizontal: 24,
        paddingBottom: 20,
        backgroundColor: '#FFF',
        borderBottomLeftRadius: 32,
        borderBottomRightRadius: 32,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.05,
        shadowRadius: 10,
        elevation: 5,
    },
    title: {
        fontSize: 28,
        fontWeight: '900',
        color: '#111827',
        marginBottom: 20,
    },
    searchContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: '#F3F4F6',
        borderRadius: 16,
        paddingHorizontal: 16,
    },
    searchIcon: {
        marginRight: 10,
    },
    searchInput: {
        flex: 1,
        height: 48,
        fontSize: 16,
        color: '#111827',
    },
    listContent: {
        padding: 24,
        paddingBottom: 100,
    },
    card: {
        backgroundColor: '#FFF',
        borderRadius: 24,
        padding: 16,
        marginBottom: 16,
        flexDirection: 'row',
        alignItems: 'center',
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.03,
        shadowRadius: 8,
        elevation: 3,
    },
    avatarContainer: {
        width: 60,
        height: 60,
        borderRadius: 20,
        backgroundColor: '#EFF6FF',
        justifyContent: 'center',
        alignItems: 'center',
        marginRight: 16,
    },
    avatarText: {
        fontSize: 24,
        fontWeight: 'bold',
        color: '#3B82F6',
    },
    infoContainer: {
        flex: 1,
    },
    nameText: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#111827',
    },
    positionText: {
        fontSize: 14,
        color: '#6B7280',
        marginTop: 2,
    },
    branchBadge: {
        backgroundColor: '#F3F4F6',
        paddingHorizontal: 8,
        paddingVertical: 2,
        borderRadius: 8,
        alignSelf: 'flex-start',
        marginTop: 6,
    },
    branchText: {
        fontSize: 11,
        fontWeight: '700',
        color: '#4B5563',
    },
    phoneContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        marginTop: 8,
    },
    phoneText: {
        fontSize: 14,
        color: '#3B82F6',
        fontWeight: '600',
        marginLeft: 6,
    },
    callButton: {
        width: 48,
        height: 48,
        borderRadius: 24,
        backgroundColor: '#10B981',
        justifyContent: 'center',
        alignItems: 'center',
        shadowColor: '#10B981',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.3,
        shadowRadius: 8,
        elevation: 5,
    },
    emptyContainer: {
        alignItems: 'center',
        justifyContent: 'center',
        marginTop: 60,
    },
    emptyText: {
        fontSize: 16,
        color: '#9CA3AF',
        marginTop: 12,
        fontWeight: '600',
    },
});
